<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use PhpToken;

/*
 * Las lecturas del reloj del sistema en un fuente PHP (regla dura 2, ADR-002
 * «Verificacion», ADR-021; V1-AR-1 y V1-BE-3 de la verificacion de la 2.2.0).
 *
 * POR QUE HACE FALTA. Deptrac concede `ExternalCode` a las capas de
 * aplicacion, y ahi cae Carbon: `CarbonImmutable::now()` en un caso de uso
 * pasaba Deptrac y todas las pruebas, porque `DomainPurityTest` solo recorre
 * `Domain/`. Y `time()`, `date()` o `new DateTimeImmutable()` son PHP, no una
 * dependencia, asi que ninguna regla de capas los ve. Un caso de uso que lee la
 * hora por su cuenta en vez de pedirla a `Clock` no se puede probar en el
 * cambio de hora ni a medianoche.
 *
 * TRES FORMAS, LAS TRES POR TOKENS para que un comentario o una cadena que
 * hable de `time()` no cuenten:
 *
 *   1. Una funcion global de PHP que lee el reloj ({@see self::FUNCTIONS}).
 *   2. `now()`, `today()`, `tomorrow()` o `yesterday()` estaticos de una fabrica
 *      de fechas (`Carbon`, `CarbonImmutable`, `Illuminate\Support\Carbon`, la
 *      facade `Date`), tambien con un alias de importacion.
 *   3. `new DateTime[Immutable]()` o `new Carbon[Immutable]()` sin instante, solo
 *      con la zona, o con un texto relativo al momento actual (`'now'`,
 *      `'today'`, `'midnight'`, `'+1 day'`, `'08:00'`...). Un texto con fecha
 *      completa (`'2026-03-29 00:00:00'`) o un `'@1700000000'` no lee el reloj.
 *
 * LO QUE NO VE, Y ESTA ESCRITO: `new DateTimeImmutable($texto)` con una
 * variable en `Application/` (lo vigila `DomainPurityTest` en el dominio; en la
 * aplicacion es la lectura legitima de una entrada ya validada) y una funcion
 * variable (`$f = 'time'; $f()`), que no se distingue por tokens de llamar a un
 * cierre (ver {@see FrameworkHelpers}).
 */
final class SystemClockCalls
{
    /**
     * Funciones de PHP que leen el reloj del sistema, en minusculas. `uniqid`
     * esta porque es la hora en hexadecimal; `hrtime` porque, aunque es
     * monotono y no da una fecha, solo se admite en la lista cerrada de
     * {@see self::MONOTONIC_TIMERS}.
     */
    public const array FUNCTIONS = [
        'time', 'date', 'gmdate', 'idate', 'mktime', 'gmmktime', 'strtotime',
        'microtime', 'hrtime', 'gettimeofday', 'getdate', 'localtime',
        'date_create', 'date_create_immutable', 'uniqid',
    ];

    /**
     * Ficheros de `Modules/` (relativos, como los da `ModuleTree::relative()`)
     * donde se admite `hrtime()`, y solo `hrtime()`: miden la duracion de una
     * operacion para telemetria o igualan el tiempo de un rechazo (RS-03). Es un
     * reloj monotono, no da la hora, y no decide nada del registro horario
     * (ADR-002 §Verificacion, nota del 26-10-2026). Un fichero nuevo que lo
     * necesite se añade aqui, a la vista, y no por omision.
     */
    public const array MONOTONIC_TIMERS = [
        'Attendance/Application/UseCase/RegisterScanBatchHandler.php',
        'Compliance/Application/Support/RetentionTelemetry.php',
        'Identity/Application/Support/CredentialTelemetry.php',
        'Shared/Application/Support/ConstantTimeFloor.php',
        'Shared/Application/Support/UseCaseInstrumentation.php',
    ];

    /** Metodos estaticos de una fabrica de fechas que devuelven el momento actual. */
    private const array STATIC_NOW = ['now', 'today', 'tomorrow', 'yesterday'];

    /** Ultimo segmento, en minusculas, de las fabricas de fechas de terceros. */
    private const array DATE_FACTORIES = ['carbon', 'carbonimmutable', 'date', 'datefactory'];

    /** Las clases de fecha cuyo constructor sin instante lee el reloj. */
    private const array DATE_CLASSES = ['datetime', 'datetimeimmutable', 'carbon', 'carbonimmutable'];

    /**
     * @param  bool  $monotonicAllowed  Si el fichero esta en {@see self::MONOTONIC_TIMERS}.
     * @return list<array{call: string, line: int}>
     */
    public static function in(string $source, bool $monotonicAllowed = false): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $aliases = self::classAliases($tokens);
        $calls = [];

        foreach ($tokens as $i => $token) {
            $call = self::functionCall($tokens, $i)
                ?? self::staticNow($tokens, $i, $aliases)
                ?? self::constructedNow($tokens, $i, $aliases);

            if ($call !== null && ! ($monotonicAllowed && $call === 'hrtime()')) {
                $calls[] = ['call' => $call, 'line' => $token->line];
            }
        }

        return $calls;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function functionCall(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];
        $previous = $tokens[$i - 1] ?? null;
        $name = strtolower(ltrim($token->text, '\\'));

        $isGlobalCall = $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
            && ($tokens[$i + 1] ?? null)?->text === '('
            && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);

        return $isGlobalCall && \in_array($name, self::FUNCTIONS, true) ? $name.'()' : null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private static function staticNow(array $tokens, int $i, array $aliases): ?string
    {
        $class = $tokens[$i];
        $method = $tokens[$i + 2] ?? null;

        if (! $class->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            || ($tokens[$i + 1] ?? null)?->text !== '::'
            || $method === null
            || ! \in_array(strtolower($method->text), self::STATIC_NOW, true)
            || ($tokens[$i + 3] ?? null)?->text !== '(') {
            return null;
        }

        $resolved = self::resolve($class->text, $aliases);

        return \in_array(self::lastSegment($resolved), self::DATE_FACTORIES, true)
            ? $class->text.'::'.$method->text.'()'
            : null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private static function constructedNow(array $tokens, int $i, array $aliases): ?string
    {
        $class = $tokens[$i + 1] ?? null;

        if (! $tokens[$i]->is(T_NEW)
            || $class === null
            || ! \in_array(self::lastSegment(self::resolve($class->text, $aliases)), self::DATE_CLASSES, true)) {
            return null;
        }

        // `new DateTimeImmutable` sin parentesis tambien es «ahora».
        if (($tokens[$i + 2] ?? null)?->text !== '(') {
            return 'new '.$class->text;
        }

        $argument = self::nowArgument($tokens[$i + 3] ?? null, $tokens[$i + 4] ?? null);

        return $argument === null ? null : 'new '.$class->text.'('.$argument.')';
    }

    /**
     * Como se escribe el primer argumento de un constructor de fecha que lee el
     * reloj, o `null` si el argumento es un instante: vacio, solo la zona por
     * nombre (`timezone: $utc`) o un texto relativo al momento actual.
     */
    private static function nowArgument(?PhpToken $first, ?PhpToken $after): ?string
    {
        return match (true) {
            $first === null, $first->text === ')' => '',
            $first->is(T_STRING) && strtolower($first->text) === 'timezone' && $after?->text === ':' => 'timezone: …',
            $first->is(T_CONSTANT_ENCAPSED_STRING)
                && \in_array($after?->text, [',', ')'], true)
                && self::readsTheClock(substr($first->text, 1, -1)) => $first->text,
            default => null,
        };
    }

    /**
     * Si un texto literal se resuelve contra el momento actual: le falta el año,
     * el mes o el dia, o lleva una parte relativa (`today`, `+1 day`,
     * `midnight`...). `'@<segundos>'` es un instante absoluto.
     */
    private static function readsTheClock(string $text): bool
    {
        if (str_starts_with($text, '@')) {
            return false;
        }

        $parsed = date_parse($text);

        return $parsed['year'] === false
            || $parsed['month'] === false
            || $parsed['day'] === false
            || isset($parsed['relative']);
    }

    /**
     * Los alias de los `use` de clase del fichero: nombre corto o alias, en
     * minusculas, a nombre completo en minusculas.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private static function classAliases(array $tokens): array
    {
        $aliases = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            // Solo los `use` de primer nivel: `use function`, `use const` y el
            // `use (...)` de un cierre no importan clases.
            if (! $tokens[$i]->is(T_USE) || ! ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $name = strtolower(ltrim($tokens[$i + 1]->text, '\\'));
            $hasAlias = ($tokens[$i + 2] ?? null)?->is(T_AS) === true;
            $alias = $hasAlias ? strtolower($tokens[$i + 3]->text ?? '') : self::lastSegment($name);
            $aliases[$alias] = $name;
        }

        return $aliases;
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private static function resolve(string $name, array $aliases): string
    {
        $lower = strtolower($name);

        if (str_starts_with($lower, '\\')) {
            return ltrim($lower, '\\');
        }

        $head = explode('\\', $lower)[0];

        return isset($aliases[$head]) ? $aliases[$head].substr($lower, \strlen($head)) : $lower;
    }

    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', strtolower($name));

        return end($segments);
    }
}
