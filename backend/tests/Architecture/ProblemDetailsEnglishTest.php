<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * CADA TEXTO FIJO DE UNA RESPUESTA DE ERROR TIENE SU INGLES EN `lang/en.json`
 * (F4a-3, RF-KI-05, revision del bloque 10).
 *
 * `ProblemDetails` escribe `title`, `detail` y los `errors` por campo en español
 * y los pasa por `__()`: el español es la clave y el ingles vive en
 * `lang/en.json`. Si falta la entrada, Laravel **no falla**: devuelve la clave,
 * y un panel en ingles recibe el texto en español. Es el fallo que dejaba el
 * `detail` de `shift-entry-superseded` y los `errors` de las correcciones en
 * castellano.
 *
 * Se leen los literales del codigo con el analizador de PHP, no con `__()`:
 *
 * - en `app/Exceptions/ProblemDetails.php`, todo literal que empieza en
 *   mayuscula y tiene un espacio, salvo las claves de un array;
 * - en `bootstrap/app.php`, los literales que se pasan a un `ProblemDetails::*`
 *   —`detail` y `errors`—, con las concatenaciones (`'…'.'…'`) unidas, y
 *   fuera de `ProblemDetails::translated()`, cuyo primer argumento es una
 *   clave de `lang/` y el tercero el respaldo tecnico en ingles.
 *
 * Fuera quedan, a proposito, los textos fijos del fichaje y del emparejamiento
 * (`ScanRejected`, `ScanNotProcessed`, `ScanHeldBack` y los dos de
 * emparejamiento): son genericos y de tiempo constante, no pasan por aqui y el
 * contrato los declara texto fijo (`info.description`).
 */

/**
 * Los tokens con significado: sin espacios ni comentarios.
 *
 * @return list<array{0: int, 1: string}|string>
 */
function problemDetailsEnglishTokens(string $code): array
{
    return array_values(array_filter(
        token_get_all($code),
        static fn (array|string $token): bool => ! \is_array($token)
            || ! \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true),
    ));
}

/**
 * El valor de un literal de comillas simples, o `null` si no lo es.
 *
 * @param  array{0: int, 1: string}|string|null  $token
 */
function problemDetailsEnglishLiteral(array|string|null $token): ?string
{
    if (! \is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || ! str_starts_with($token[1], "'")) {
        return null;
    }

    return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($token[1], 1, -1));
}

/** Si parece texto para una persona: empieza en mayuscula y tiene un espacio. */
function problemDetailsEnglishIsText(string $text): bool
{
    return preg_match('/^[\p{Lu}¿«]/u', $text) === 1 && str_contains($text, ' ');
}

/**
 * El literal que empieza en `$i` con sus concatenaciones (`'a '.'b'`, una sola
 * frase partida en dos lineas), y la posicion de su ultimo trozo.
 *
 * @param  list<array{0: int, 1: string}|string>  $tokens
 * @return array{0: string, 1: int}|null
 */
function problemDetailsEnglishJoined(array $tokens, int $i): ?array
{
    $text = problemDetailsEnglishLiteral($tokens[$i]);

    if ($text === null) {
        return null;
    }

    while (($tokens[$i + 1] ?? null) === '.' && ($next = problemDetailsEnglishLiteral($tokens[$i + 2] ?? null)) !== null) {
        $text .= $next;
        $i += 2;
    }

    return [$text, $i];
}

/**
 * Los literales de texto que no son claves de array, en las posiciones que
 * `$counts` admite.
 *
 * @param  list<array{0: int, 1: string}|string>  $tokens
 * @param  Closure(int): bool  $counts
 * @return list<string>
 */
function problemDetailsEnglishTexts(array $tokens, Closure $counts): array
{
    $texts = [];

    for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
        $joined = $counts($i) ? problemDetailsEnglishJoined($tokens, $i) : null;

        if ($joined === null) {
            continue;
        }

        [$text, $i] = $joined;
        $next = $tokens[$i + 1] ?? null;
        $isKey = \is_array($next) && $next[0] === T_DOUBLE_ARROW;

        if (! $isKey && problemDetailsEnglishIsText($text)) {
            $texts[] = $text;
        }
    }

    return $texts;
}

/**
 * El metodo de `ProblemDetails::metodo(` que empieza en `$i`, o `null`.
 *
 * @param  list<array{0: int, 1: string}|string>  $tokens
 */
function problemDetailsEnglishCallAt(array $tokens, int $i): ?string
{
    $class = $tokens[$i];
    $colon = $tokens[$i + 1] ?? null;
    $method = $tokens[$i + 2] ?? null;

    $isCall = \is_array($class) && $class[1] === 'ProblemDetails'
        && \is_array($colon) && $colon[0] === T_DOUBLE_COLON
        && \is_array($method) && ($tokens[$i + 3] ?? null) === '(';

    return $isCall ? $method[1] : null;
}

/**
 * La posicion del parentesis que cierra el que abre en `$open`.
 *
 * @param  list<array{0: int, 1: string}|string>  $tokens
 */
function problemDetailsEnglishClosing(array $tokens, int $open): int
{
    $depth = 0;

    for ($j = $open, $count = \count($tokens); $j < $count; $j++) {
        $depth += match ($tokens[$j]) {
            '(', '[' => 1,
            ')', ']' => -1,
            default => 0,
        };

        if ($depth === 0) {
            return $j;
        }
    }

    return $count - 1;
}

/**
 * Las posiciones que quedan dentro de una llamada `ProblemDetails::*(…)`, sin
 * las de `ProblemDetails::translated(…)`: su primer argumento es una clave de
 * `lang/` y el tercero el respaldo tecnico en ingles.
 *
 * @param  list<array{0: int, 1: string}|string>  $tokens
 * @return array<int, true>
 */
function problemDetailsEnglishCallPositions(array $tokens): array
{
    $inside = [];
    $excluded = [];

    foreach (array_keys($tokens) as $i) {
        $method = problemDetailsEnglishCallAt($tokens, $i);

        if ($method === null) {
            continue;
        }

        $positions = range($i + 3, problemDetailsEnglishClosing($tokens, $i + 3));

        if ($method === 'translated') {
            $excluded += array_fill_keys($positions, true);
        } else {
            $inside += array_fill_keys($positions, true);
        }
    }

    return array_diff_key($inside, $excluded);
}

/**
 * @return array<string, list<string>> Por fichero, los textos que deberian tener ingles.
 */
function problemDetailsEnglishTextsByFile(): array
{
    $own = problemDetailsEnglishTokens(Repo::contents('backend/app/Exceptions/ProblemDetails.php'));
    $bootstrap = problemDetailsEnglishTokens(Repo::contents('backend/bootstrap/app.php'));
    $calls = problemDetailsEnglishCallPositions($bootstrap);

    return [
        'ProblemDetails.php' => problemDetailsEnglishTexts($own, static fn (int $i): bool => true),
        'bootstrap/app.php' => problemDetailsEnglishTexts($bootstrap, static fn (int $i): bool => isset($calls[$i])),
    ];
}

/**
 * @return array<string, string>
 */
function problemDetailsEnglishCatalogue(): array
{
    /** @var array<string, string> $entries */
    $entries = json_decode(Repo::contents('backend/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);

    return $entries;
}

it('encuentra los textos que tiene que comprobar (red de seguridad)', function (): void {
    $textos = problemDetailsEnglishTextsByFile();

    expect(\count($textos['ProblemDetails.php']))->toBeGreaterThan(30)
        ->and($textos['bootstrap/app.php'])->toContain('La hora de salida tiene que ser posterior a la de entrada.')
        // Una concatenacion unida, no sus dos mitades.
        ->and($textos['bootstrap/app.php'])->toContain(
            'Ese tramo ya no es la version vigente: lo corrigio o lo anulo alguien antes. '
            .'Vuelve a cargar la jornada antes de repetir la operacion.',
        );
})->group('RF-KI-05');

it('cada texto fijo de un error tiene su entrada en lang/en.json', function (): void {
    $catalogo = problemDetailsEnglishCatalogue();
    $faltan = [];

    foreach (problemDetailsEnglishTextsByFile() as $fichero => $textos) {
        foreach ($textos as $texto) {
            if (! isset($catalogo[$texto]) || trim($catalogo[$texto]) === '') {
                $faltan[] = $fichero.': '.$texto;
            }
        }
    }

    expect(array_values(array_unique($faltan)))->toBe([], 'Textos de error sin ingles en lang/en.json');
})->group('RF-KI-05');
