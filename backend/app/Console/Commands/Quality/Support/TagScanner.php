<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use Symfony\Component\Finder\Finder;

/**
 * Extractor de etiquetas de los dos formatos del §9.6:
 *
 *     Pest / PHPUnit   it('...')->group('RN-05', 'RF-AT-08');   #[Group('RN-05')]
 *     Playwright       test('...', { tag: ['@RF-KI-03'] }, ...)
 *
 * Lee el codigo fuente en vez de arrancar los dos ejecutores. El motivo es que
 * las dos mitades tienen que salir de la misma pasada: Playwright vive en tres
 * aplicaciones de Node y pedirle la lista de pruebas cuesta mas que la etapa
 * entera de la CI (~10 s, §10.1). La contrapartida esta declarada: una etiqueta
 * compuesta en tiempo de ejecucion no se ve. Por eso lo que no encaja no se
 * descarta, se devuelve en `malformed` y el comando lo enseña.
 */
final class TagScanner
{
    /** `it('nombre')`, `test('nombre')`, `describe('nombre')`. */
    private const string DECLARATION = '/\b(?:it|test|describe)\s*\(\s*[\'"](?<name>[^\'"\n]*)[\'"]/u';

    /** `->group('RN-05', 'RF-AT-08')` y `#[Group('RN-05')]`. */
    private const string PEST_TAG = '/(?:->\s*group|#\[\s*Group)\s*\(\s*(?<args>[^)]*)\)/u';

    /** `{ tag: ['@RF-KI-03'] }` y `{ tag: '@RF-KI-03' }`. */
    private const string PLAYWRIGHT_TAG = '/\btag\s*:\s*(?<args>\[[^\]]*\]|[\'"][^\'"\n]*[\'"])/u';

    /** Cadena entrecomillada suelta dentro de los argumentos de una etiqueta. */
    private const string QUOTED = '/[\'"](?<value>[^\'"\n]*)[\'"]/u';

    /** Lo que alguien ha querido escribir como requisito, bien o mal. */
    private const string LOOKS_LIKE_REQUIREMENT = '/^@?R[A-Z]{0,3}-/u';

    /**
     * Una prueba que no se ejecuta no cubre nada.
     *
     * `it('conserva el registro anterior')->group('RL-04')->skip('pendiente de
     * la 2.4')` haria figurar RL-04 como cubierto en la matriz —que se entrega
     * como evidencia de que cada obligacion legal tiene una prueba automatica—
     * sin que se verifique absolutamente nada. Lo mismo con `->todo()` y con
     * `test.skip(` de Playwright.
     *
     * No se descartan en silencio: van a `malformed`, que el comando enseña. El
     * requisito se queda entonces sin cobertura y `--check` bloquea, que es el
     * lado correcto por el que fallar.
     *
     * El alcance es DELIBERADAMENTE el salto declarativo de la cadena, no un
     * `markTestSkipped()` dentro del cuerpo: ese es condicional —«salta si no
     * hay extension»— y leyendo el fichero como texto no hay forma de saber si
     * la condicion se cumple. Marcarlo tambien habria quitado cobertura a
     * pruebas que si se ejecutan.
     */
    private const string SKIPPED_AFTER = '/->\s*(?:skip|todo)\s*\(/u';

    /**
     * El mismo salto, encadenado por DELANTE: `->skip('...')->group('RL-04')`.
     *
     * Se ancla al final de la ventana —que termina justo donde empieza la
     * etiqueta— para no entrar nunca en el cuerpo de la prueba. Sin ese ancla,
     * una prueba que menciona `->skip(` en su cuerpo perdia sus propias
     * etiquetas: paso de verdad al escribir esto, y el sintoma fue que las
     * pruebas de este mismo fichero dejaron de cubrir RQ-13.
     */
    private const string SKIPPED_BEFORE = '/(?:->\s*(?:skip|todo)\s*\([^()]*\)|\btest\s*\.\s*(?:skip|fixme)\s*\([^()]*)\s*$/u';

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
        $pattern = $tool === 'playwright' ? ['*.spec.ts', '*.test.ts'] : ['*.php'];

        $files = [];

        foreach (Finder::create()->files()->in($directory)->name($pattern)->sortByName() as $file) {
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
        $expression = $tool === 'playwright' ? self::PLAYWRIGHT_TAG : self::PEST_TAG;

        preg_match_all($expression, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tests = [];
        $malformed = [];

        foreach ($matches as $match) {
            $classified = $this->classify($match['args'][0]);

            foreach ($classified['malformed'] as $tag) {
                $malformed[] = $this->relative($file).': '.$tag;
            }

            if ($classified['requirements'] === []) {
                continue;
            }

            $offset = $match[0][1];

            if (self::isSkipped($source, $offset)) {
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

        $requirements = [];
        $malformed = [];

        foreach ($matches['value'] as $value) {
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

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * ¿La etiqueta pertenece a una prueba que no se va a ejecutar?
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
    private static function isSkipped(string $source, int $offset): bool
    {
        $end = strpos($source, ';', $offset);
        $length = ($end === false ? strlen($source) : $end) - $offset;

        if (preg_match(self::SKIPPED_AFTER, substr($source, $offset, $length)) === 1) {
            return true;
        }

        $lookbehind = min($offset, self::CHAIN_LOOKBEHIND);

        return preg_match(self::SKIPPED_BEFORE, substr($source, $offset - $lookbehind, $lookbehind)) === 1;
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
