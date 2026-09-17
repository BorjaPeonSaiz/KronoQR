<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use Symfony\Component\Finder\Finder;

/**
 * Extractor de etiquetas de los tres formatos del §9.6:
 *
 *     Pest / PHPUnit   it('...')->group('RN-05', 'RF-AT-08');   #[Group('RN-05')]
 *     Playwright       test('...', { tag: ['@RF-KI-03'] }, ...)
 *     k6               scan: { ..., tags: { requirements: 'RNF-P-06 RNF-P-02' } }
 *
 * Lee el codigo fuente en vez de arrancar los ejecutores. El motivo es que las
 * tres mitades tienen que salir de la misma pasada: Playwright vive en tres
 * aplicaciones de Node y pedirle la lista de pruebas cuesta mas que la etapa
 * entera de la CI (~10 s, §10.1), y la prueba de carga tarda minutos y necesita
 * la pila levantada. La contrapartida esta declarada: una etiqueta compuesta en
 * tiempo de ejecucion no se ve. Por eso lo que no encaja no se descarta, se
 * devuelve en `malformed` y el comando lo enseña.
 */
final class TagScanner
{
    /** `it('nombre')`, `test('nombre')`, `describe('nombre')`. */
    private const string DECLARATION = '/\b(?:it|test|describe)\s*\(\s*[\'"](?<name>[^\'"\n]*)[\'"]/u';

    /** `->group('RN-05', 'RF-AT-08')` y `#[Group('RN-05')]`. */
    private const string PEST_TAG = '/(?:->\s*group|#\[\s*Group)\s*\(\s*(?<args>[^)]*)\)/u';

    /** `{ tag: ['@RF-KI-03'] }` y `{ tag: '@RF-KI-03' }`. */
    private const string PLAYWRIGHT_TAG = '/\btag\s*:\s*(?<args>\[[^\]]*\]|[\'"][^\'"\n]*[\'"])/u';

    /**
     * El bloque `tags: { ... }` de k6, que es etiqueta NATIVA del ejecutor: la
     * misma que viaja en cada muestra del CSV y por la que agrupa el agregado.
     * De ahi que se exija estar dentro de `tags` y no valga un `requirements:`
     * suelto en cualquier parte del fichero —una constante, una opcion del
     * escenario o un comentario—: lo que la matriz declara cubierto tiene que
     * ser lo mismo que k6 mide.
     *
     * El cuerpo no admite llaves anidadas a proposito: `tags` es un mapa plano
     * de cadenas para k6, asi que `[^{}]*` no deja que la etiqueta de un
     * escenario se lleve por delante la del siguiente.
     */
    private const string K6_TAG = '/\btags\s*:\s*\{(?<body>[^{}]*)\}/u';

    /** El valor de `requirements` dentro de ese bloque. */
    private const string K6_REQUIREMENTS = '/\brequirements\s*:\s*[\'"`](?<value>[^\'"`\n]*)[\'"`]/u';

    /**
     * Cadenas y comentarios de un fichero JavaScript.
     *
     * Solo se usan para CEGAR las llaves que contienen (ver `withoutLiterals`),
     * nunca para borrar texto: si un comentario o una cadena pudieran abrir o
     * cerrar un objeto, el nombre del escenario que se atribuye a la etiqueta
     * seria el de cualquier otro. Las cadenas van antes que los comentarios en
     * la alternancia porque un `'https://…'` no es media linea comentada.
     */
    private const string JS_LITERAL = '/\'(?:\\\\.|[^\'\\\\\n])*\'|"(?:\\\\.|[^"\\\\\n])*"|`(?:\\\\.|[^`\\\\])*`|\/\/[^\n]*|\/\*.*?\*\//su';

    /** `scan: {`, `'scan': {` — la clave a la que pertenece un objeto. */
    private const string OBJECT_KEY = '/[\'"]?(?<key>[A-Za-z_$][\w$-]*)[\'"]?\s*:\s*$/u';

    /** Las llaves que abren y cierran un objeto, ya cegadas las de los literales. */
    private const string BRACES = '/[{}]/u';

    /**
     * Un `*.js` que k6 puede ejecutar: exporta `options` y declara `scenarios`.
     *
     * Son DOS condiciones y por eso van como dos anticipaciones sobre el
     * contenido entero, no como dos llamadas a `contains()`: el Finder de
     * Symfony las une con O, no con Y, y bastaba con nombrar `scenarios` —cosa
     * que hace `aggregate.js`, porque los informa— para colarse.
     */
    private const string K6_SCENARIO_FILE = '/(?=[\s\S]*\bexport\s+const\s+options\b)(?=[\s\S]*\bscenarios\s*:)/';

    /** Cuanto texto se mira por delante de una llave para encontrar su clave. */
    private const int KEY_LOOKBEHIND = 120;

    /** Cadena entrecomillada suelta dentro de los argumentos de una etiqueta. */
    private const string QUOTED = '/[\'"](?<value>[^\'"\n]*)[\'"]/u';

    /** Lo que alguien ha querido escribir como requisito, bien o mal. */
    private const string LOOKS_LIKE_REQUIREMENT = '/^@?R[A-Z]{0,3}-/u';

    /**
     * Una prueba que no se ejecuta NUNCA no cubre nada.
     *
     * `it('conserva el registro anterior')->group('RL-04')->skip('pendiente de
     * la 2.4')` haria figurar RL-04 como cubierto en la matriz —que se entrega
     * como evidencia de que cada obligacion legal tiene una prueba automatica—
     * sin que se verifique absolutamente nada. Lo mismo con `->todo()` y con
     * `test.skip('nombre', …)` de Playwright.
     *
     * No se descartan en silencio: van a `malformed`, que el comando enseña. El
     * requisito se queda entonces sin cobertura y `--check` bloquea, que es el
     * lado correcto por el que fallar.
     *
     * OJO CON EL SALTO CONDICIONAL, que es otra cosa: `->skip(! hayChromium(),
     * 'no esta instalado')` se ejecuta siempre que la herramienta este, y es el
     * patron normal de una prueba que necesita algo que no hay en todos los
     * entornos. Esas SI cuentan, y se enumeran aparte en la matriz para que se
     * sepa que su verde depende del entorno. `skipArguments()` las distingue.
     *
     * El alcance sigue siendo DELIBERADAMENTE el salto declarativo de la
     * cadena, no un `markTestSkipped()` dentro del cuerpo: leyendo el fichero
     * como texto no hay forma de saber a que altura del cuerpo esta ni de que
     * depende.
     *
     * Los parentesis se leen con recursion (`(?&call)`) y no con `[^()]*`
     * porque la condicion los lleva dentro: sin eso, los siete saltos de
     * `FpmPoolRenderTest` —`->skip(! hayFpmDeVerdad(), '…')`— no encajaban en
     * el patron entero y se contaban como saltos incondicionales.
     */
    private const string SKIPPED_AFTER = '/->\s*(?:skip|todo)\s*(?<call>\((?:[^()]++|(?&call))*\))/u';

    /**
     * El mismo salto cuando los parentesis no se dejan leer (una llamada sin
     * cerrar dentro de la ventana, por ejemplo). Se trata como incondicional a
     * proposito: ante la duda, el requisito se queda sin cobertura y `--check`
     * bloquea, que es el lado correcto por el que fallar.
     */
    private const string SKIPPED_AFTER_LOOSE = '/->\s*(?:skip|todo)\s*\(/u';

    /**
     * El mismo salto, encadenado por DELANTE: `->skip('...')->group('RL-04')`.
     *
     * Se ancla al final de la ventana —que termina justo donde empieza la
     * etiqueta— para no entrar nunca en el cuerpo de la prueba. Sin ese ancla,
     * una prueba que menciona `->skip(` en su cuerpo perdia sus propias
     * etiquetas: paso de verdad al escribir esto, y el sintoma fue que las
     * pruebas de este mismo fichero dejaron de cubrir RQ-13.
     */
    private const string SKIPPED_BEFORE = '/->\s*(?:skip|todo)\s*(?<call>\((?:[^()]++|(?&call))*\))\s*$/u';

    /**
     * La forma declarativa de Playwright, donde la etiqueta va DENTRO de la
     * llamada: `test.skip('nombre', { tag: ['@RN-05'] }, fn)`. Los argumentos
     * llegan truncados justo donde empieza la etiqueta, que es suficiente para
     * mirar el primero: `test.skip(browserName === 'firefox', …)` es
     * condicional y `test.skip('nombre', …)` no.
     */
    private const string SKIPPED_BEFORE_DECLARATION = '/\btest\s*\.\s*(?:skip|fixme)\s*\((?<args>[^()]*)$/u';

    /**
     * Con que empieza un argumento que es una cadena literal.
     *
     * @var list<string>
     */
    private const array QUOTES = ['\'', '"', '`'];

    /** Cuanto texto se mira por detras de la etiqueta. Una cadena no es mas larga. */
    private const int CHAIN_LOOKBEHIND = 200;

    /**
     * @param  array<string, list<string>>  $roots  Herramienta -> directorios.
     */
    public function __construct(private readonly array $roots, private readonly string $basePath) {}

    public function scan(): TagScan
    {
        $tests = [];
        $missing = [];
        $scanned = [];
        $malformed = [];

        foreach ($this->roots as $tool => $directories) {
            $scanned[$tool] = 0;
            $missing[$tool] = [];

            foreach ($directories as $directory) {
                if (! is_dir($directory)) {
                    $missing[$tool][] = $this->relative($directory);

                    continue;
                }

                foreach ($this->filesIn($tool, $directory) as $file) {
                    $scanned[$tool]++;
                    $found = $this->readFile($tool, $file);
                    $tests = array_merge($tests, $found['tests']);
                    $malformed = array_merge($malformed, $found['malformed']);
                }
            }
        }

        usort($tests, static fn (TaggedTest $left, TaggedTest $right): int => [$left->file, $left->line] <=> [$right->file, $right->line]);

        sort($malformed);

        return new TagScan($tests, $missing, $scanned, array_values(array_unique($malformed)));
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $tool, string $directory): array
    {
        $pattern = match ($tool) {
            'playwright' => ['*.spec.ts', '*.test.ts'],
            'k6' => ['*.js'],
            default => ['*.php'],
        };

        $files = [];

        // `.results/` es la salida de `run.sh`, no fuente: lleva los CSV y los
        // registros de cada instancia. Hoy el Finder ya lo salta por empezar
        // con punto, pero eso es un efecto secundario de `ignoreDotFiles()` y
        // no una decision; escrito aqui, sobrevive a que alguien la cambie.
        $finder = Finder::create()->files()->in($directory)->exclude(['.results', 'node_modules'])
            ->name($pattern)->sortByName();

        if ($tool === 'k6') {
            // Bajo `load-tests/k6/` no todo lo que es `*.js` es prueba de
            // carga: `aggregate.js` es el agregado de resultados —habla de
            // `scenarios` porque los informa— y `aggregate.test.js` son SUS
            // pruebas, que corren con Node y no con k6. Solo es escenario lo
            // que k6 puede ejecutar: un fichero que exporta `options` con
            // `scenarios` dentro. Sin este filtro, la matriz contaba como
            // «ficheros explorados» dos herramientas que no miden nada.
            $finder->notName('*.test.js')->contains(self::K6_SCENARIO_FILE);
        }

        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * @return array{tests: list<TaggedTest>, malformed: list<string>}
     */
    private function readFile(string $tool, string $file): array
    {
        $source = (string) file_get_contents($file);

        return $tool === 'k6'
            ? $this->readScenarios($file, $source)
            : $this->readChain($tool, $file, $source);
    }

    /**
     * Pest y Playwright: la etiqueta cuelga de una declaracion de prueba.
     *
     * @return array{tests: list<TaggedTest>, malformed: list<string>}
     */
    private function readChain(string $tool, string $file, string $source): array
    {
        $expression = $tool === 'playwright' ? self::PLAYWRIGHT_TAG : self::PEST_TAG;

        preg_match_all($expression, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tests = [];
        $malformed = [];

        foreach ($matches as $match) {
            $classified = $this->classify($match['args'][0]);

            foreach ($classified['malformed'] as $tag) {
                $malformed[] = $this->malformedTag($file, $tag);
            }

            if ($classified['requirements'] === []) {
                continue;
            }

            $offset = $match[0][1];
            $skip = self::skipArguments($source, $offset);

            if ($skip !== null && ! self::isConditional($skip)) {
                $malformed[] = $this->relative($file).':'.self::lineAt($source, $offset)
                    .': la prueba esta saltada y no cubre '.implode(', ', $classified['requirements']);

                continue;
            }

            $tests[] = new TaggedTest(
                $tool,
                $this->relative($file),
                self::lineAt($source, $offset),
                self::declarationBefore($source, $offset),
                $classified['requirements'],
                $skip !== null,
            );
        }

        return ['tests' => $tests, 'malformed' => $malformed];
    }

    /**
     * k6: la etiqueta es una propiedad del escenario, no una cadena colgada de
     * una declaracion. No hay «prueba saltada» que valga aqui —un escenario
     * apagado se borra o se le pone `rate: 0`, y ninguna de las dos cosas se ve
     * como un `->skip(`—, asi que el unico riesgo es atribuir la etiqueta al
     * escenario equivocado. De ahi que el nombre salga de la estructura del
     * objeto y no de la linea anterior.
     *
     * @return array{tests: list<TaggedTest>, malformed: list<string>}
     */
    private function readScenarios(string $file, string $source): array
    {
        // Una sola pasada por fichero: la usan la busqueda de etiquetas y la
        // atribucion del nombre, y conserva las posiciones del original.
        $code = self::withoutLiterals($source);

        preg_match_all(self::K6_TAG, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tests = [];
        $malformed = [];

        foreach ($matches as $match) {
            if (preg_match(self::K6_REQUIREMENTS, $match['body'][0], $found, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $classified = $this->classifyAll(self::identifiersIn($found['value'][0]));

            foreach ($classified['malformed'] as $tag) {
                $malformed[] = $this->malformedTag($file, $tag);
            }

            if ($classified['requirements'] === []) {
                continue;
            }

            $tests[] = new TaggedTest(
                'k6',
                $this->relative($file),
                self::lineAt($source, $match['body'][1] + $found['value'][1]),
                self::scenarioAt($code, $match[0][1]),
                $classified['requirements'],
            );
        }

        return ['tests' => $tests, 'malformed' => $malformed];
    }

    /**
     * Separa los identificadores de requisito del resto de grupos (`slow`,
     * `smoke`) y aparta los que lo parecen pero no lo son. `RN-O5` con la letra
     * O es la clase de erratas por la que un requisito se queda sin prueba
     * mientras alguien cree haberla escrito.
     *
     * @return array{requirements: list<string>, malformed: list<string>}
     */
    private function classify(string $arguments): array
    {
        preg_match_all(self::QUOTED, $arguments, $matches);

        return $this->classifyAll($matches['value']);
    }

    /**
     * Lo mismo, sobre identificadores ya sueltos: es la forma en la que llegan
     * los de k6, que van todos dentro de una misma cadena.
     *
     * @param  list<string>  $values
     * @return array{requirements: list<string>, malformed: list<string>}
     */
    private function classifyAll(array $values): array
    {
        $requirements = [];
        $malformed = [];

        foreach ($values as $value) {
            $candidate = ltrim($value, '@');

            if (preg_match(RequirementRange::IDENTIFIER, $candidate) === 1) {
                $requirements[] = $candidate;

                continue;
            }

            if (preg_match(self::LOOKS_LIKE_REQUIREMENT, $value) === 1) {
                $malformed[] = $value;
            }
        }

        return ['requirements' => array_values(array_unique($requirements)), 'malformed' => $malformed];
    }

    /**
     * Los identificadores de un valor de `requirements`.
     *
     * El formato acordado es el espacio —`'RNF-P-06 RNF-P-02 RQ-08'`— porque es
     * lo que k6 acepta como valor de etiqueta. Se toleran ademas la coma y la
     * arroba de Playwright: quien escribe el escenario viene de etiquetar
     * pruebas E2E y no tiene por que acordarse de cual de los tres separadores
     * toca. La tolerancia NO alcanza a lo que esta mal escrito: `RN-O5` sigue
     * saliendo por `malformed`, que es el unico sitio donde se ve.
     *
     * @return list<string>
     */
    private static function identifiersIn(string $value): array
    {
        return preg_split('/[\s,]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Ciega las llaves que viven dentro de una cadena o de un comentario,
     * conservando la longitud del fichero para que las posiciones sigan siendo
     * las del original.
     *
     * Trae de regalo que una etiqueta comentada deje de contar: sin su `{`, el
     * bloque `tags` ya no encaja. Es el lado correcto por el que fallar —el
     * requisito se queda sin cobertura y `--check` bloquea— y es coherente con
     * las pruebas saltadas de Pest.
     */
    private static function withoutLiterals(string $source): string
    {
        return (string) preg_replace_callback(
            self::JS_LITERAL,
            static fn (array $match): string => strtr((string) $match[0], ['{' => ' ', '}' => ' ']),
            $source,
        );
    }

    /**
     * Nombre de la prueba de k6: la clave del escenario, que es la clave de
     * objeto abierta mas cercana por delante de la etiqueta.
     *
     *     scenarios: { scan: { executor: '…', tags: { requirements: '…' } } }
     *                   ^^^^  <- esto
     *
     * Se recorre la estructura y no la linea anterior porque la etiqueta suele
     * ir al final del escenario, a varias lineas de su clave. Si la llave mas
     * cercana es anonima —un objeto dentro de una lista— se sigue hacia fuera
     * hasta encontrar una con nombre: mejor `scenarios` que `(fichero)`.
     */
    private static function scenarioAt(string $code, int $offset): string
    {
        $prefix = substr($code, 0, $offset);

        // Se salta de llave en llave y no caracter a caracter: aparte de ser
        // mas corto, no deja un indice que comparar con la longitud del texto,
        // que es el borde que la mutacion encontro sin prueba que lo matara.
        preg_match_all(self::BRACES, $prefix, $braces, PREG_OFFSET_CAPTURE);

        $open = [];

        foreach ($braces[0] as [$brace, $position]) {
            if ($brace === '{') {
                $open[] = self::keyBefore($prefix, $position);

                continue;
            }

            array_pop($open);
        }

        while ($open !== []) {
            $key = array_pop($open);

            if ($key !== null) {
                return $key;
            }
        }

        return '(fichero)';
    }

    /**
     * La clave a la que pertenece la llave que abre en `$brace`, o `null` si no
     * tiene ninguna: `{` de un objeto dentro de una lista, de un cuerpo de
     * funcion o de un `=` en vez de un `:`.
     *
     * La ventana es CORTA a proposito. Entre una clave y su `{` solo cabe el
     * espacio en blanco —y, como mucho, un comentario ya cegado—, asi que 120
     * caracteres sobran; mirar mas atras solo puede traer la clave equivocada,
     * porque el ancla `$` del patron dejaria de garantizar que lo encontrado es
     * lo que abre ESTA llave.
     */
    private static function keyBefore(string $prefix, int $brace): ?string
    {
        $start = max(0, $brace - self::KEY_LOOKBEHIND);

        if (preg_match(self::OBJECT_KEY, substr($prefix, $start, $brace - $start), $found) !== 1) {
            return null;
        }

        return $found['key'];
    }

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * Los argumentos del salto encadenado a la etiqueta, o `null` si no hay
     * salto. Una cadena vacia es `->skip()`, que tambien es un salto.
     *
     * Se mira la CADENA, no la sentencia entera, porque el salto puede ir a
     * cualquiera de los dos lados de la etiqueta:
     *
     *     it('...')->group('RL-04')->skip('pendiente');
     *     it('...')->skip('pendiente')->group('RL-04');
     *
     * Por delante se mira una ventana corta y anclada; por detras, hasta el `;`
     * que cierra la sentencia. Lo que nunca se mira es el cuerpo de la prueba:
     * que una prueba HABLE de `->skip(` no la convierte en saltada.
     */
    private static function skipArguments(string $source, int $offset): ?string
    {
        $end = strpos($source, ';', $offset);
        $length = ($end === false ? strlen($source) : $end) - $offset;
        $after = substr($source, $offset, $length);

        if (preg_match(self::SKIPPED_AFTER, $after, $found) === 1) {
            return self::inside($found['call']);
        }

        if (preg_match(self::SKIPPED_AFTER_LOOSE, $after) === 1) {
            return '';
        }

        $lookbehind = min($offset, self::CHAIN_LOOKBEHIND);
        $before = substr($source, $offset - $lookbehind, $lookbehind);

        if (preg_match(self::SKIPPED_BEFORE, $before, $found) === 1) {
            return self::inside($found['call']);
        }

        if (preg_match(self::SKIPPED_BEFORE_DECLARATION, $before, $found) === 1) {
            return $found['args'];
        }

        return null;
    }

    /**
     * ¿El salto depende de una condicion que se evalua al ejecutar?
     *
     * El criterio es el PRIMER argumento, que es el que decide en las dos
     * herramientas:
     *
     *     ->skip()                            incondicional, no cubre
     *     ->skip('pendiente de la 2.4')       incondicional, no cubre
     *     test.skip('nombre', { tag: … })     incondicional, no cubre
     *     ->skip(! hayChromium(), 'motivo')   condicional, SI cubre
     *     test.skip(browserName === 'x', …)   condicional, SI cubre
     *
     * Si es una cadena literal, es el motivo o el nombre de la prueba y el
     * salto es siempre. Cualquier otra cosa es una expresion que puede ser
     * falsa, y entonces la prueba se ejecuta y verifica lo que dice cubrir. Que
     * su verde dependa del entorno no la convierte en mentira: la matriz lo
     * enumera aparte para que se lea con esa reserva.
     */
    private static function isConditional(string $arguments): bool
    {
        $first = ltrim($arguments);

        if ($first === '') {
            return false;
        }

        foreach (self::QUOTES as $quote) {
            if (str_starts_with($first, $quote)) {
                return false;
            }
        }

        return true;
    }

    /** El contenido de una llamada `(...)`, sin los parentesis. */
    private static function inside(string $call): string
    {
        return substr($call, 1, -1);
    }

    /** El aviso de una etiqueta que parece un requisito y no lo es. */
    private function malformedTag(string $file, string $tag): string
    {
        return $this->relative($file).': etiqueta con forma de requisito que no lo es → '.$tag;
    }

    /**
     * Nombre de la prueba a la que pertenece la etiqueta: la ultima declaracion
     * `it(...)`, `test(...)` o `describe(...)` que hay por encima.
     */
    private static function declarationBefore(string $source, int $offset): string
    {
        preg_match_all(self::DECLARATION, substr($source, 0, $offset), $matches);

        $names = $matches['name'];

        return $names === [] ? '(fichero)' : (string) end($names);
    }

    private function relative(string $path): string
    {
        $normalized = self::normalize($path);
        $base = self::normalize($this->basePath).'/';

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }

    /**
     * Colapsa `..` y separadores de Windows. Las rutas llegan de la
     * configuracion como `base_path('../frontend-kiosk/tests/e2e')`, que es
     * valida para el sistema de ficheros pero no se puede comparar con nada.
     */
    private static function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
