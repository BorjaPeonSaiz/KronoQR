<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Que operaciones del contrato tienen al menos una respuesta validada con
 * Spectator (`assertValidResponse`) en la suite (Q3 de la verificacion de la
 * 2.2.0, RQ-06/RQ-13).
 *
 * Se lee el codigo de las pruebas como texto, igual que el resto de las pruebas
 * de arquitectura, y se reproduce lo que hace Spectator en ejecucion: valida la
 * respuesta de la **ultima** peticion que paso por el kernel. Asi que cada
 * `assertValidResponse` se atribuye a la ultima peticion que la precede dentro
 * de la misma sentencia de primer nivel del fichero (un `it(...)`, un
 * `beforeEach(...)` o una funcion ayudante). Una llamada a una funcion
 * ayudante **del mismo fichero** cuenta como la ultima peticion que hace esa
 * funcion: es como se escriben `fichar()` o `sincronizar()`.
 *
 * La ruta de la peticion se reconstruye de su primer argumento: los literales
 * se conservan y cualquier expresion (`{$uuid}`, `'…/'.$uuid`) pasa a ser un
 * segmento comodin, que casa con un `{parametro}` del contrato. Una ruta que es
 * una variable entera no se puede resolver y no cuenta: es preferible un falso
 * hueco, que se ve y se arregla con una prueba explicita, a un falso cubierto.
 */
final class ContractCoverage
{
    private const string WILDCARD = "\x00";

    /** @var list<string> */
    private const array VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Las operaciones del contrato, como `METODO /ruta`.
     *
     * @return list<string>
     */
    public static function operations(string $openapiFile): array
    {
        $document = Yaml::parseFile($openapiFile);
        $paths = \is_array($document) && \is_array($document['paths'] ?? null) ? $document['paths'] : [];
        $operations = [];

        foreach ($paths as $path => $item) {
            foreach (\is_array($item) ? array_keys($item) : [] as $verb) {
                if (\in_array($verb, self::VERBS, true)) {
                    $operations[] = strtoupper($verb).' '.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * Operacion => ficheros donde se valida su respuesta.
     *
     * @param  list<string>  $operations
     * @param  list<string>  $files
     * @return array<string, list<string>>
     */
    public static function validated(array $operations, array $files): array
    {
        $validated = [];

        foreach ($files as $file) {
            foreach (self::validatedIn((string) file_get_contents($file), $operations) as $operation) {
                $validated[$operation][] = $file;
            }
        }

        return $validated;
    }

    /**
     * Las operaciones cuya respuesta valida un fichero de pruebas.
     *
     * @param  list<string>  $operations
     * @return list<string>
     */
    public static function validatedIn(string $source, array $operations): array
    {
        return array_values(array_unique(array_map(
            static fn (array $validation): string => $validation[0],
            self::validationsIn($source, $operations),
        )));
    }

    /**
     * Los codigos de respuesta que declara cada operacion del contrato, como
     * `METODO /ruta` => lista de codigos.
     *
     * @return array<string, list<int>>
     */
    public static function responses(string $openapiFile): array
    {
        $document = Yaml::parseFile($openapiFile);
        $paths = \is_array($document) && \is_array($document['paths'] ?? null) ? $document['paths'] : [];
        $responses = [];

        foreach ($paths as $path => $item) {
            foreach (\is_array($item) ? $item : [] as $verb => $operation) {
                if (\in_array($verb, self::VERBS, true)) {
                    $responses[strtoupper((string) $verb).' '.$path] = self::codesOf($operation);
                }
            }
        }

        ksort($responses);

        return $responses;
    }

    /**
     * Los codigos de respuesta de una operacion del YAML, ordenados.
     *
     * @return list<int>
     */
    private static function codesOf(mixed $operation): array
    {
        $responses = \is_array($operation) && \is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $codes = array_map(intval(...), array_keys($responses));
        sort($codes);

        return $codes;
    }

    /**
     * Par `METODO /ruta CODIGO` => ficheros donde se valida esa respuesta con su
     * codigo escrito: `assertValidResponse(404)`.
     *
     * Una validacion **sin codigo literal** (`assertValidResponse()`, o con una
     * variable de un dataset) cuenta para la operacion en {@see validated()},
     * pero no para ningun par: no se puede saber, leyendo la prueba, que
     * respuesta valido. Adivinarlo por un `assertStatus` cercano daria por
     * cubierto un par con una asercion sobre otra respuesta; escribir el codigo
     * cuesta tres caracteres y ademas lo comprueba (`assertStatus` incluido).
     *
     * @param  list<string>  $operations
     * @param  list<string>  $files
     * @return array<string, list<string>>
     */
    public static function validatedPairs(array $operations, array $files): array
    {
        $validated = [];

        foreach ($files as $file) {
            foreach (self::validatedPairsIn((string) file_get_contents($file), $operations) as $pair) {
                $validated[$pair][] = $file;
            }
        }

        return $validated;
    }

    /**
     * Los pares `METODO /ruta CODIGO` que valida un fichero de pruebas.
     *
     * @param  list<string>  $operations
     * @return list<string>
     */
    public static function validatedPairsIn(string $source, array $operations): array
    {
        $pairs = [];

        foreach (self::validationsIn($source, $operations) as [$operation, $code]) {
            if ($code !== null) {
                $pairs[] = $operation.' '.$code;
            }
        }

        return array_values(array_unique($pairs));
    }

    /**
     * Los codigos que barre un fichero de pruebas: una llamada a
     * `ContractSweep::discrepancies(N, ...)`, con `N` literal, en una sentencia
     * de un fichero que carga el contrato.
     *
     * La operacion de cada peticion del barrido no se puede leer del texto —sale
     * del contrato en ejecucion—, asi que el barrido se reconoce por su forma y
     * lo que cubre lo dice el propio `ContractSweep`: todas las operaciones que
     * declaran `N`, menos las que aparta con su motivo. `discrepancies()` se
     * niega a correr sin el contrato cargado, asi que reconocerla no es fiarse
     * de un nombre.
     *
     * @return list<int>
     */
    public static function sweptCodesIn(string $source): array
    {
        $statements = self::topLevelStatements(self::significantTokens($source));
        $loaders = self::contractLoaders($statements);
        $everywhere = self::loadsEverywhere($statements, $loaders);
        $codes = [];

        foreach ($statements as $tokens) {
            foreach (array_keys($tokens) as $i) {
                $isSweep = $tokens[$i][1] === 'ContractSweep'
                    && ($tokens[$i + 1][1] ?? '') === '::'
                    && ($tokens[$i + 2][1] ?? '') === 'discrepancies'
                    && ($everywhere || self::loadsContract($tokens, $loaders));
                $codes[] = $isSweep ? self::firstIntegerArgument($tokens, $i + 3) : null;
            }
        }

        $codes = array_values(array_unique(array_filter($codes, static fn (?int $code): bool => $code !== null)));
        sort($codes);

        return $codes;
    }

    /**
     * El primer argumento de la llamada cuyo `(` esta en la posicion dada, si
     * es un entero literal.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function firstIntegerArgument(array $tokens, int $open): ?int
    {
        $first = self::arguments($tokens, $open)[0];

        return \count($first) === 1 && $first[0][0] === T_LNUMBER ? (int) $first[0][1] : null;
    }

    /**
     * Cada validacion del fichero: la operacion a la que se atribuye y su codigo
     * literal, o null si no lo lleva.
     *
     * @param  list<string>  $operations
     * @return list<array{0: string, 1: int|null}>
     */
    private static function validationsIn(string $source, array $operations): array
    {
        $statements = self::topLevelStatements(self::significantTokens($source));
        $helpers = self::helperRequests($statements, $operations);
        $loaders = self::contractLoaders($statements);
        $everywhere = self::loadsEverywhere($statements, $loaders);
        $validated = [];

        foreach ($statements as $tokens) {
            if ($everywhere || self::loadsContract($tokens, $loaders)) {
                $validated = [...$validated, ...self::walk($tokens, $operations, $helpers)['validated']];
            }
        }

        return $validated;
    }

    /**
     * El argumento de la llamada cuyo `(` esta en la posicion dada, si es un
     * unico entero literal: `(404)`. Null en cualquier otro caso.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function integerArgument(array $tokens, int $open): ?int
    {
        $arguments = self::arguments($tokens, $open);

        return \count($arguments) === 1 && \count($arguments[0]) === 1 && $arguments[0][0][0] === T_LNUMBER
            ? (int) $arguments[0][0][1]
            : null;
    }

    /**
     * ¿Valida contra el contrato el cuerpo de UNA prueba de este fichero?
     *
     * Es el criterio que usa `TestLevelGateTest` para dar a una prueba de
     * feature el nivel «contrato» (RQ-14), y vive aqui para que las dos puertas
     * no puedan divergir: el cuerpo llama a `assertValidResponse` —o a una
     * funcion ayudante del fichero que lo hace— **y** el contrato esta cargado,
     * por un `beforeEach` del fichero, por el propio cuerpo o por un ayudante al
     * que llama. Sin lo segundo la asercion pasa sin comprobar nada.
     *
     * El cuerpo puede llegar cortado (desde el `it(` hasta la linea de su
     * etiqueta): se tokeniza tal cual, que para buscar llamadas basta.
     */
    public static function bodyValidatesContract(string $fileSource, string $body): bool
    {
        $statements = self::topLevelStatements(self::significantTokens($fileSource));
        $loaders = self::contractLoaders($statements);
        $tokens = self::significantTokens("<?php\n".$body);

        return self::callsValidation($tokens, self::validatingHelpers($statements))
            && (self::loadsEverywhere($statements, $loaders) || self::loadsContract($tokens, $loaders));
    }

    /**
     * ¿Carga el contrato un `beforeEach` del fichero?
     *
     * @param  list<list<array{0: int, 1: string}>>  $statements
     * @param  list<string>  $loaders
     */
    private static function loadsEverywhere(array $statements, array $loaders): bool
    {
        return array_filter(
            $statements,
            static fn (array $tokens): bool => ($tokens[0][1] ?? '') === 'beforeEach' && self::loadsContract($tokens, $loaders),
        ) !== [];
    }

    /**
     * Las funciones de primer nivel del fichero que llaman a `assertValidResponse`.
     *
     * @param  list<list<array{0: int, 1: string}>>  $statements
     * @return list<string>
     */
    private static function validatingHelpers(array $statements): array
    {
        $helpers = [];

        foreach ($statements as $tokens) {
            if (($tokens[0][1] ?? '') === 'function' && ($tokens[1][0] ?? 0) === T_STRING && self::callsValidation($tokens, [])) {
                $helpers[] = $tokens[1][1];
            }
        }

        return $helpers;
    }

    /**
     * ¿Llaman los tokens a `assertValidResponse`, o a una de las funciones dadas?
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @param  list<string>  $helpers
     */
    private static function callsValidation(array $tokens, array $helpers): bool
    {
        foreach (array_keys($tokens) as $i) {
            $called = self::calledFunctionAt($tokens, $i);

            if (self::isMethodCall($tokens, $i, 'assertValidResponse') || ($called !== null && \in_array($called, $helpers, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Carga la sentencia el contrato con `Spectator::using(...)`?
     *
     * Sin eso el middleware de Spectator no valida nada y `assertValidResponse`
     * pasa en verde sobre cualquier respuesta: la asercion existe y no comprueba
     * nada. Cuenta si lo hace un `beforeEach` del fichero, la propia sentencia o
     * una funcion ayudante del fichero a la que llama (`escenarioDeLote()`).
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @param  list<string>  $loaders
     */
    private static function loadsContract(array $tokens, array $loaders = []): bool
    {
        foreach (array_keys($tokens) as $i) {
            $calledFunction = self::calledFunctionAt($tokens, $i);

            if (self::isSpectatorUsing($tokens, $i) || ($calledFunction !== null && \in_array($calledFunction, $loaders, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function isSpectatorUsing(array $tokens, int $i): bool
    {
        return $tokens[$i][1] === 'Spectator'
            && ($tokens[$i + 1][1] ?? '') === '::'
            && ($tokens[$i + 2][1] ?? '') === 'using';
    }

    /**
     * El nombre de la funcion suelta a la que se llama en la posicion dada
     * (`fichar(`), o null si ahi no hay una: un metodo (`->get(`, `Api::as(`) o
     * una declaracion (`function fichar(`) no lo son.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function calledFunctionAt(array $tokens, int $i): ?string
    {
        $isCall = $tokens[$i][0] === T_STRING
            && ($tokens[$i + 1][1] ?? '') === '('
            && ! \in_array($tokens[$i - 1][1] ?? '', ['->', '::', 'function', 'new'], true);

        return $isCall ? $tokens[$i][1] : null;
    }

    /**
     * Las funciones de primer nivel del fichero que cargan el contrato, directa o
     * indirectamente.
     *
     * @param  list<list<array{0: int, 1: string}>>  $statements
     * @return list<string>
     */
    private static function contractLoaders(array $statements): array
    {
        $loaders = [];

        foreach ([1, 2] as $_) {
            foreach ($statements as $tokens) {
                if (($tokens[0][1] ?? '') === 'function' && ($tokens[1][0] ?? 0) === T_STRING && self::loadsContract(\array_slice($tokens, 2), $loaders)) {
                    $loaders[] = $tokens[1][1];
                }
            }
        }

        return array_values(array_unique($loaders));
    }

    /**
     * La operacion a la que corresponde una peticion, o null si la ruta no casa
     * con ninguna. Si casa con varias (`/employees/import` y
     * `/employees/{uuid}`), gana la de mas texto literal, que es la que
     * resolveria el router.
     *
     * @param  list<string>  $operations
     */
    public static function match(string $verb, string $uri, array $operations): ?string
    {
        $path = strtok($uri, '?');
        $best = null;
        $bestLiteral = -1;

        foreach ($operations as $operation) {
            [$opVerb, $template] = explode(' ', $operation, 2);
            $literal = \strlen((string) preg_replace('/\{[^}]+\}/', '', $template));

            if ($opVerb === $verb && $literal > $bestLiteral && preg_match(self::templatePattern($template), (string) $path) === 1) {
                $best = $operation;
                $bestLiteral = $literal;
            }
        }

        return $best;
    }

    private static function templatePattern(string $template): string
    {
        $parts = preg_split('/(\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = implode('', array_map(
            static fn (string $part): string => str_starts_with($part, '{') ? '[^/]+' : preg_quote($part, '#'),
            $parts,
        ));

        return '#^'.$regex.'/?$#';
    }

    /**
     * Recorre una sentencia y devuelve lo que valida y la ultima peticion: su
     * operacion, `null` si la ruta no se pudo resolver o `false` si no hubo
     * ninguna.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @param  list<string>  $operations
     * @param  array<string, string|false|null>  $helpers
     * @return array{validated: list<array{0: string, 1: int|null}>, last: string|false|null}
     */
    private static function walk(array $tokens, array $operations, array $helpers): array
    {
        $last = false;
        $validated = [];

        foreach (array_keys($tokens) as $i) {
            if (self::isMethodCall($tokens, $i, 'assertValidResponse') && \is_string($last)) {
                $validated[] = [$last, self::integerArgument($tokens, $i + 1)];
            }

            $request = self::requestOf($tokens, $i, $operations, $helpers);
            $last = $request === false ? $last : $request;
        }

        return ['validated' => $validated, 'last' => $last];
    }

    /**
     * La peticion que hace la llamada de la posicion dada, con el mismo
     * significado que la de {@see walk()}: un `->metodo(` HTTP o una funcion
     * ayudante del fichero que hace peticiones.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @param  list<string>  $operations
     * @param  array<string, string|false|null>  $helpers
     */
    private static function requestOf(array $tokens, int $i, array $operations, array $helpers): string|false|null
    {
        if (self::isMethodCall($tokens, $i)) {
            return $tokens[$i][1] === 'assertValidResponse' ? false : self::requestAt($tokens, $i, $operations);
        }

        $helper = self::calledFunctionAt($tokens, $i);

        return $helper === null ? false : ($helpers[$helper] ?? false);
    }

    /**
     * ¿Hay en la posicion dada una llamada `->metodo(`, y si se pide, a ese
     * metodo?
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function isMethodCall(array $tokens, int $i, ?string $method = null): bool
    {
        return $tokens[$i][0] === T_STRING
            && ($tokens[$i - 1][1] ?? '') === '->'
            && ($tokens[$i + 1][1] ?? '') === '('
            && ($method === null || $tokens[$i][1] === $method);
    }

    /**
     * La operacion de la llamada `->metodo(` en la posicion dada si es una
     * peticion HTTP; `null` si lo es pero su ruta no casa con el contrato o no se
     * puede leer, y `false` si no es una peticion.
     *
     * Con una ruta literal que empieza por `/api/` es una peticion seguro. Con una
     * ruta que es una expresion (`->get($enlace)`) solo se sabe si la cadena
     * empieza en `Api::` o en `$this`: `$headers->get($clave)` no lo es, y
     * confundirla haria perder la peticion anterior.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @param  list<string>  $operations
     */
    private static function requestAt(array $tokens, int $i, array $operations): string|false|null
    {
        $method = strtolower(str_replace('Json', '', $tokens[$i][1]));
        $arguments = self::arguments($tokens, $i + 1);

        if (\in_array($method, ['json', 'call', 'raw'], true)) {
            $verb = strtoupper(trim(self::literal($arguments[0] ?? []), self::WILDCARD));
            $uri = self::literal($arguments[1] ?? []);
        } elseif (\in_array($method, [...self::VERBS, 'upload'], true)) {
            $verb = $method === 'upload' ? 'POST' : strtoupper($method);
            $uri = self::literal($arguments[0] ?? []);
        } else {
            return false;
        }

        if (str_starts_with($uri, '/api/')) {
            return self::match($verb, str_replace(self::WILDCARD, 'x', $uri), $operations);
        }

        return \in_array(self::chainRoot($tokens, $i - 1), ['Api', '$this'], true) ? null : false;
    }

    /**
     * El primer eslabon de la cadena de llamadas que acaba en el `->` dado:
     * `Api` en `Api::as($t)->withHeaders([])->get(`, `$this` en `$this->get(`.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function chainRoot(array $tokens, int $arrow): string
    {
        $i = $arrow - 1;

        while ($i >= 0) {
            if ($tokens[$i][1] === ')') {
                $i = self::openingParenthesis($tokens, $i) - 1;
            }

            $text = $tokens[$i][1] ?? '';
            $before = $tokens[$i - 1][1] ?? '';

            if ($before === '->') {
                $i -= 2;
            } elseif ($before === '::') {
                return $tokens[$i - 2][1] ?? '';
            } else {
                return $text;
            }
        }

        return '';
    }

    /**
     * La posicion del `(` que abre el `)` dado.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function openingParenthesis(array $tokens, int $close): int
    {
        $depth = 0;

        for ($i = $close; $i >= 0; $i--) {
            $depth += $tokens[$i][1] === ')' ? 1 : ($tokens[$i][1] === '(' ? -1 : 0);

            if ($depth === 0) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Los argumentos de la llamada cuyo `(` esta en la posicion dada, cada uno
     * como su lista de tokens.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<list<array{0: int, 1: string}>>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $arguments = [[]];
        $count = \count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i][1];
            $depth += \in_array($text, ['(', '[', '{', '${'], true) ? 1 : (\in_array($text, [')', ']', '}'], true) ? -1 : 0);

            if ($depth === 0) {
                break;
            }

            if ($depth === 1 && $text === ',') {
                $arguments[] = [];
            } elseif ($i !== $open) {
                $arguments[\count($arguments) - 1][] = $tokens[$i];
            }
        }

        return $arguments;
    }

    /**
     * El texto de una expresion con los literales tal cual y el resto como
     * comodin.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function literal(array $tokens): string
    {
        $text = '';

        foreach ($tokens as [$id, $value]) {
            $text .= match (true) {
                $id === T_CONSTANT_ENCAPSED_STRING => stripcslashes(substr($value, 1, -1)),
                $id === T_ENCAPSED_AND_WHITESPACE => $value,
                $value === '"' || $value === '.' => '',
                default => self::WILDCARD,
            };
        }

        return (string) preg_replace('/\x00+/', self::WILDCARD, $text);
    }

    /**
     * Ultima peticion que hace cada funcion de primer nivel del fichero, con el
     * mismo significado que la de {@see walk()}.
     *
     * @param  list<list<array{0: int, 1: string}>>  $statements
     * @param  list<string>  $operations
     * @return array<string, string|false|null>
     */
    private static function helperRequests(array $statements, array $operations): array
    {
        $functions = [];

        foreach ($statements as $tokens) {
            if (($tokens[0][1] ?? '') === 'function' && ($tokens[1][0] ?? 0) === T_STRING) {
                $functions[$tokens[1][1]] = $tokens;
            }
        }

        // Dos pasadas: un ayudante puede llamar a otro declarado despues.
        $helpers = array_fill_keys(array_keys($functions), false);

        foreach ([1, 2] as $_) {
            foreach ($functions as $name => $tokens) {
                $helpers[$name] = self::walk(\array_slice($tokens, 2), $operations, $helpers)['last'];
            }
        }

        return $helpers;
    }

    /**
     * Los tokens de una sentencia de primer nivel, sin espacios ni comentarios.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<list<array{0: int, 1: string}>>
     */
    private static function topLevelStatements(array $tokens): array
    {
        $statements = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            $current[] = $token;
            $text = $token[1];
            $depth += \in_array($text, ['(', '[', '{', '${'], true) ? 1 : (\in_array($text, [')', ']', '}'], true) ? -1 : 0);

            if ($depth === 0 && ($text === ';' || ($text === '}' && $current[0][1] === 'function'))) {
                $statements[] = $current;
                $current = [];
            }
        }

        return $statements;
    }

    /**
     * Tokens de PHP como `[id, texto]`, sin espacios ni comentarios. Los de un
     * solo caracter llevan id 0.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function significantTokens(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            $token = \is_array($token) ? [$token[0], $token[1]] : [0, $token];

            if (! \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
