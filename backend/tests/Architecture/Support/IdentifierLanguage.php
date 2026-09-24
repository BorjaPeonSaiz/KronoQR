<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use PhpToken;

/*
 * El idioma de los identificadores de PHP, comprobado sobre los TOKENS.
 *
 * POR QUE EXISTE. El doc 02 §3.5 manda escribir el codigo en ingles y usar el
 * glosario del doc 01 §13 como puente con el lenguaje ubicuo (*tramo* ->
 * `ShiftEntry`, *jornada* -> `WorkDay`). Hasta la decision del 24-09-2026 esa
 * convencion no la verificaba ninguna herramienta, y la regla que gobierna esa
 * seccion dice que **una convencion que no verifica una herramienta es una
 * sugerencia**. Mezclar idiomas en los identificadores no es una cuestion de
 * gusto: produce dos nombres para la misma cosa —`Tramo` y `ShiftEntry`— y a
 * partir de ahi nadie sabe cual de los dos es el concepto del dominio.
 *
 * POR QUE POR TOKENS Y NO POR EXPRESION REGULAR SOBRE EL TEXTO. Porque el
 * repositorio esta lleno, a proposito, de espanol que NO es un identificador:
 * los docblocks y los comentarios explican el porque en espanol, las claves de
 * `i18n` y los textos de usuario van en espanol, y los nombres de tabla y de
 * columna que viajan dentro de cadenas son `snake_case` del esquema. Un
 * `grep -i jornada` sobre `app/` denuncia cientos de aciertos correctos. Lo que
 * la regla prohibe es el IDENTIFICADOR DECLARADO, y eso solo se distingue
 * tokenizando: {@see self::declaredIn()} solo mira nombres de clase, interfaz,
 * enum, trait, funcion, metodo, constante, caso de enum y variable —que incluye
 * propiedades y parametros—, y las cadenas y los comentarios no son ninguna de
 * esas cosas.
 *
 * DONDE APLICA. En `backend/app/`. En `tests/` no: alli los ayudantes, las
 * constantes y los conjuntos de datos pueden ir en el idioma del escenario,
 * igual que las descripciones de `it()`, que es lo que hace que una prueba
 * fallida se lea sola. La contrapartida en el frontend es la regla `id-match`
 * de ESLint sobre `src/**` de las tres SPA y de `packages/web-kit`.
 */
final class IdentifierLanguage
{
    /**
     * El glosario del doc 01 §13, en la forma en que aparece en un identificador.
     *
     * No es "palabras en espanol" en general —eso no se puede decidir sin un
     * diccionario— sino las veinte palabras del lenguaje ubicuo del producto,
     * que son justo las que tienen traduccion acordada y las unicas que producen
     * el dano de tener dos nombres para el mismo concepto.
     *
     * @var list<string>
     */
    public const array GLOSSARY = [
        'Fichaje',
        'Jornada',
        'Tramo',
        'Empleado',
        'Ausencia',
        'Quiosco',
        'Credencial',
        'Incidencia',
        'Turno',
        'Centro',
        'Departamento',
        'Contrato',
        'Usuario',
        'Pausa',
        'Descanso',
        'Nomina',
        'Informe',
        'Ajuste',
        'Licencia',
        'Tarjeta',
    ];

    /**
     * Conectores del espanol que NO se denuncian solos, y por que no hace falta.
     *
     * Sueltos son trampas: `Content` empieza por `Con`, `Delete` por `Del`,
     * `Parameter` por `Para`, `Since` por `Sin`. Una regla que los cazara sueltos
     * habria denunciado medio armazon de Laravel el primer dia y habria acabado
     * desactivada entera, que es el unico final posible de una regla que grita
     * ante el patron correcto.
     *
     * Pegados a una palabra del glosario **ya estan cubiertos y no se les dedica
     * una rama de la expresion**: en `ConTramo`, `SinPausa` o `DesdeJornada` lo
     * que la denuncia es el segmento `Tramo`, `Pausa` o `Jornada`, que va
     * capitalizado y no necesita saber que lleva delante. Una rama que no puede
     * ser la unica causa de un fallo es codigo muerto, y en una regla de
     * arquitectura el codigo muerto es peor que en otro sitio: parece que
     * comprueba algo.
     *
     * La lista se conserva porque es la que el conjunto de datos de
     * `IdentifierLanguageTest` usa para afirmar que estos seis siguen limpios.
     *
     * @var list<string>
     */
    public const array CONNECTORS = [
        'Con',
        'Del',
        'Para',
        'Sin',
        'Hasta',
        'Desde',
    ];

    /**
     * Los identificadores de un arbol de ficheros que incumplen la regla, ya
     * escritos como `ruta:linea (naturaleza) nombre -> motivo`.
     *
     * Devuelve la frase entera y no una estructura porque el valor de esta
     * prueba esta en su mensaje de fallo: quien la vea en rojo tiene que poder
     * renombrar sin abrir nada. Y el recorrido vive aqui, no en el cuerpo de la
     * prueba, para que alli quede un *arrange / act / assert* sin bucles
     * (doc 02 §3.5).
     *
     * Recorre con `scandir` a traves de {@see ModuleTree::phpFilesUnder()}: sobre
     * el bind mount de Docker Desktop `RecursiveDirectoryIterator` pierde
     * ficheros sin avisar, y una prueba de arquitectura que no ve un fichero
     * **pasa en verde**.
     *
     * @return list<string>
     */
    public static function offencesUnder(string $directory): array
    {
        $offences = [];

        foreach (ModuleTree::phpFilesUnder($directory) as $file) {
            foreach (self::declaredIn($file) as $declared) {
                $offence = self::offenceOf($declared['name']);

                if ($offence === null) {
                    continue;
                }

                $offences[] = ModuleTree::relative($file, $directory).':'.$declared['line']
                    .' ('.$declared['kind'].') '.$declared['name'].' -> '.$offence;
            }
        }

        return array_values(array_unique($offences));
    }

    /**
     * El motivo por el que un identificador incumple la regla, o `null` si cumple.
     *
     * Devuelve el motivo y no un booleano porque el mensaje de fallo tiene que
     * decir QUE hacer: renombrar `getJornada()` no se le ocurre a nadie a partir
     * de un `false`.
     */
    public static function offenceOf(string $name): ?string
    {
        // Un identificador de PHP solo admite `[A-Za-z0-9_]` y bytes >= 0x80, asi
        // que cualquier cosa fuera del primer conjunto es un caracter no ASCII:
        // `$anoFiscal` pasa, `$anioFiscal` pasa, `$a{n-con-tilde}oFiscal` no.
        if (preg_match('/[^A-Za-z0-9_]/', $name) === 1) {
            return 'lleva caracteres fuera de ASCII';
        }

        if (preg_match(self::glossaryPattern(), $name, $match) === 1) {
            return 'contiene la palabra del glosario (doc 01 §13) "'.$match[0]
                .'", que en el codigo va en ingles';
        }

        return null;
    }

    /**
     * La expresion que reconoce una palabra del glosario como SEGMENTO COMPLETO.
     *
     * El limite de palabra de un `camelCase` no es `\b`: es "hasta la siguiente
     * mayuscula, digito, guion bajo o fin de cadena". Sin ese limite, `Turnover`
     * seria un `Turno`, `Informer` un `Informe` y `centroid` un `Centro`. Con el,
     * los tres pasan y `TurnoAbierto`, `InformeMensual` y `centroDeTrabajo` no.
     *
     * Se contempla el segmento capitalizado en cualquier posicion —`getJornada`,
     * `TramoRepository`—, el segmento en minuscula solo al principio —el unico
     * sitio donde un `camelCase` lo pone: `$tramoVigente`— y el segmento en
     * mayusculas de una constante `SCREAMING_SNAKE_CASE`: `MAX_JORNADA`.
     *
     * Y EL PLURAL, que es el que de verdad aparece: lo que alguien escribe con
     * prisa no es `$tramo`, es `$tramos` o `$credenciales`. Sin el sufijo, la
     * regla habria pasado en verde sobre la mitad de los casos que existe para
     * detectar. Ninguna palabra inglesa del arbol cae por anadirlo: `Turnos`,
     * `Centros` e `Informes` no son palabras.
     */
    private static function glossaryPattern(): string
    {
        $glossary = implode('|', self::GLOSSARY);

        // `(?:es|s)?(?=[A-Z0-9_]|$)`: plural opcional y aqui termina el segmento.
        $endOfSegment = '(?:es|s)?(?=[A-Z0-9_]|$)';

        $capitalised = '(?:'.$glossary.')'.$endOfSegment;
        $leadingLowercase = '^(?:'.strtolower($glossary).')'.$endOfSegment;
        $screamingSnake = '(?:^|_)(?:'.strtoupper($glossary).')(?:ES|S)?(?:_|$)';

        return '/'.implode('|', [$capitalised, $leadingLowercase, $screamingSnake]).'/';
    }

    /**
     * Los identificadores DECLARADOS en un fichero, con su linea y su naturaleza.
     *
     * Lo que se mira y lo que no:
     *
     * - Si: nombre de clase, interfaz, trait, enum, funcion, metodo, constante
     *   —de clase o de fichero, con tipo o sin el—, caso de enum y variable. Las
     *   propiedades y los parametros, incluidos los promovidos del constructor,
     *   son tokens `T_VARIABLE` y entran por ahi.
     * - No: cadenas, comentarios, docblocks ni HTML. El tokenizador los da como
     *   tokens propios y aqui se descartan antes de empezar, que es toda la razon
     *   de no usar una expresion regular sobre el texto.
     * - No: `new class`, `Foo::class`, `use function foo;` ni los cierres, que no
     *   declaran ningun nombre en este fichero.
     * - No: el caso de un enum respaldado por cadena cuyo nombre es la ESCRITURA
     *   DE SU PROPIO VALOR ({@see self::mirrorsItsOwnValue()}).
     *
     * Un `T_VARIABLE` que sea un USO y no una declaracion entra tambien, y da
     * igual: si `$jornada` se usa en el fichero, se ha declarado en algun sitio,
     * y el nombre que hay que renombrar es el mismo.
     *
     * @return list<array{kind: string, name: string, line: int}>
     */
    public static function declaredIn(string $file): array
    {
        return self::declaredInSource((string) file_get_contents($file));
    }

    /**
     * Lo mismo que {@see self::declaredIn()} sobre codigo fuente en memoria.
     *
     * Existe para que la propia heuristica sea comprobable con fragmentos
     * escritos a mano: una regla de arquitectura que solo se puede ejercitar
     * contra el arbol real pasa en verde el dia que deja de detectar algo.
     *
     * @return list<array{kind: string, name: string, line: int}>
     */
    public static function declaredInSource(string $source): array
    {
        $tokens = self::significantTokens($source);
        $declared = [];

        foreach (array_keys($tokens) as $index) {
            $declared = [...$declared, ...self::declarationsAt($tokens, $index)];
        }

        return $declared;
    }

    /**
     * Lo que declara —si declara algo— el token que esta en esa posicion.
     *
     * Un metodo por naturaleza de declaracion en lugar de una escalera de `if`s
     * en el recorrido: la escalera llegaba a complejidad ciclomatica 40 y el
     * limite del §3.5 es 10. Cada rama se lee y se rompe por separado.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function declarationsAt(array $tokens, int $index): array
    {
        $token = $tokens[$index];

        return match (true) {
            $token->is(T_VARIABLE) => self::variableAt($token),
            $token->is([T_INTERFACE, T_TRAIT, T_ENUM]) => self::typeNameAt($tokens, $index),
            $token->is(T_CLASS) => self::classNameAt($tokens, $index),
            $token->is(T_FUNCTION) => self::functionNameAt($tokens, $index),
            $token->is(T_CONST) => self::constantNamesAt($tokens, $index),
            $token->is(T_CASE) => self::enumCaseAt($tokens, $index),
            default => [],
        };
    }

    /**
     * Propiedades, parametros y variables locales, que son el mismo token.
     *
     * `$this` no lo nombro nadie.
     *
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function variableAt(PhpToken $token): array
    {
        if ($token->text === '$this') {
            return [];
        }

        return [['kind' => 'variable', 'name' => substr($token->text, 1), 'line' => $token->line]];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function typeNameAt(array $tokens, int $index): array
    {
        $name = $tokens[$index + 1] ?? null;

        if ($name === null || ! $name->is(T_STRING)) {
            return [];
        }

        // `T_INTERFACE` -> `interface`, para que el mensaje diga que es.
        $kind = strtolower(substr((string) $tokens[$index]->getTokenName(), 2));

        return [['kind' => $kind, 'name' => $name->text, 'line' => $name->line]];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function classNameAt(array $tokens, int $index): array
    {
        $previous = $tokens[$index - 1] ?? null;

        // `new class {…}` no tiene nombre y `Foo::class` no declara nada.
        if ($previous !== null && $previous->is([T_NEW, T_DOUBLE_COLON])) {
            return [];
        }

        $name = $tokens[$index + 1] ?? null;

        if ($name === null || ! $name->is(T_STRING)) {
            return [];
        }

        return [['kind' => 'class', 'name' => $name->text, 'line' => $name->line]];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function functionNameAt(array $tokens, int $index): array
    {
        // `use function App\foo;` importa, no declara.
        if (($tokens[$index - 1] ?? null)?->is(T_USE) === true) {
            return [];
        }

        $next = $tokens[$index + 1] ?? null;

        // `function &porReferencia()`: el `&` va entre medias. Y un cierre no
        // tiene nombre: ahi el siguiente token es `(`.
        $name = $next !== null && $next->text === '&' ? ($tokens[$index + 2] ?? null) : $next;

        if ($name === null || ! $name->is(T_STRING)) {
            return [];
        }

        return [['kind' => 'function', 'name' => $name->text, 'line' => $name->line]];
    }

    /**
     * Constantes de fichero y de clase: hasta el `;`, todo `T_STRING` pegado a un
     * `=` es un nombre declarado.
     *
     * Asi entran `const A = 1, B = 2;` y las constantes tipadas de PHP 8.3
     * (`const array MODULES = [...]`), donde el primer `T_STRING` es el tipo.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function constantNamesAt(array $tokens, int $index): array
    {
        $names = [];

        for ($i = $index + 1, $count = \count($tokens); $i < $count && $tokens[$i]->text !== ';'; $i++) {
            if ($tokens[$i]->is(T_STRING) && ($tokens[$i + 1]->text ?? '') === '=') {
                $names[] = ['kind' => 'const', 'name' => $tokens[$i]->text, 'line' => $tokens[$i]->line];
            }
        }

        return $names;
    }

    /**
     * Caso de enum, no rama de `switch`: aquel termina en `;` o en `= 'valor';`,
     * y este en `:`.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{kind: string, name: string, line: int}>
     */
    private static function enumCaseAt(array $tokens, int $index): array
    {
        $name = $tokens[$index + 1] ?? null;

        if ($name === null || ! $name->is(T_STRING)) {
            return [];
        }

        $after = $tokens[$index + 2]->text ?? '';

        if ($after !== ';' && $after !== '=') {
            return [];
        }

        $backingValue = $after === '=' ? ($tokens[$index + 3] ?? null) : null;

        if (self::mirrorsItsOwnValue($name->text, $backingValue)) {
            return [];
        }

        return [['kind' => 'enum case', 'name' => $name->text, 'line' => $name->line]];
    }

    /**
     * Si el caso de enum no es un nombre elegido, sino la escritura de su propio
     * valor respaldado: `case EMPLEADO = 'empleado';`.
     *
     * POR QUE ESTO NO ES UNA EXCEPCION AD HOC. La regla del §3.5 prohibe que el
     * PROGRAMADOR nombre en espanol lo que el glosario ya nombra en ingles. Un
     * enum respaldado cuyo caso repite su valor no esta nombrando nada: esta
     * escribiendo un DATO que ya existe fuera del codigo —en la columna
     * `roles.name` de toda instalacion, en `shift_corrections.reason_code`, en el
     * Anexo C del doc 01 y en `openapi.yaml`— y que el propio enunciado del
     * requisito enumera en espanol (RF-ID-02). Traducir el caso a ingles dejando
     * el valor como esta produce exactamente el dano que la regla persigue: dos
     * nombres para la misma cosa, uno en el codigo y otro en la base de datos, y
     * un diccionario que hay que mantener entre los dos. Es la misma razon por la
     * que la comprobacion no mira las cadenas: los nombres de tabla, de columna y
     * las claves de `i18n` tampoco son identificadores del programador.
     *
     * Y ES ESTRECHA A PROPOSITO: solo vale si el nombre es el valor, letra por
     * letra salvo las mayusculas. `case TRAMO = 'shift_entry';` se denuncia,
     * porque ahi si hay dos nombres, y `case Tramo;` en un enum sin respaldo
     * tambien, porque ahi no hay ningun dato al que atenerse.
     */
    private static function mirrorsItsOwnValue(string $caseName, ?PhpToken $backingValue): bool
    {
        if ($backingValue === null || ! $backingValue->is(T_CONSTANT_ENCAPSED_STRING)) {
            return false;
        }

        $value = trim($backingValue->text, '\'"');

        return strtoupper($value) === strtoupper($caseName);
    }

    /**
     * Los tokens que pueden ser un identificador: sin espacios, sin comentarios,
     * sin docblocks y sin las etiquetas de apertura y cierre.
     *
     * Se descartan de golpe para que el recorrido de {@see self::declaredIn()}
     * pueda mirar "el token anterior" y "el siguiente" sin tropezar con el
     * formato: `class    Foo` y `class /* … *\/ Foo` son la misma declaracion.
     *
     * @return list<PhpToken>
     */
    private static function significantTokens(string $source): array
    {
        $significant = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML])) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }
}
