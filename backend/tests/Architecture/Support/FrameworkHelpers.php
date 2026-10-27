<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Support\Facades\Facade;

/*
 * Los helpers globales y las facades de Laravel, leidos del propio framework, y
 * su deteccion en el codigo fuente (ADR-002 «Verificacion», H3 y R1-AR-01 de la
 * verificacion de la 2.2.0).
 *
 * POR QUE HACE FALTA. Deptrac razona sobre referencias a CLASES: ve un
 * `use Illuminate\Support\Facades\Cache;` pero no ve `config('x')`, `app()`,
 * `cache()` ni `now()`, que son funciones globales sin ningun `use`. Un
 * `config()` en el dominio incumple a la vez la regla dura 1 (el dominio no
 * conoce el framework) y la 14 (los umbrales llegan resueltos por un puerto), y
 * pasaba la CI en verde.
 *
 * LA LISTA NO SE ESCRIBE A MANO: se lee de `vendor/laravel/framework/src/
 * Illuminate/<componente>/helpers.php`, que es donde el framework declara cada
 * helper detras de un `function_exists()`. Una version de Laravel que anada un
 * helper lo anade aqui sin que nadie toque esta clase. Los `functions.php` del
 * framework quedan fuera a proposito: declaran funciones CON espacio de nombres
 * (`Illuminate\Support\enum_value`), que solo se alcanzan con un
 * `use function Illuminate\…`, y ese import ya lo rechaza `DomainPurityTest`.
 *
 * LA DETECCION VA POR TOKENS, NO POR EXPRESION REGULAR, para no confundir un
 * metodo propio con el helper del mismo nombre: `$periodo->value()`,
 * `self::with(...)` o `public function value()` no son llamadas a `value()`, y
 * un comentario o una cadena que diga «config()» tampoco.
 *
 * Y SE RECORRE CON `scandir`, como `ModuleTree`, por el mismo motivo: sobre el
 * bind mount de Docker Desktop el iterador recursivo pierde ficheros sin avisar.
 */
final class FrameworkHelpers
{
    /**
     * Los helpers globales que declara `laravel/framework`, en minusculas (PHP
     * no distingue mayusculas en los nombres de funcion).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $illuminate = \dirname(__DIR__, 3).'/vendor/laravel/framework/src/Illuminate';
        $names = [];

        foreach (scandir($illuminate) ?: [] as $component) {
            $helpers = $illuminate.'/'.$component.'/helpers.php';

            if ($component !== '.' && $component !== '..' && is_file($helpers)) {
                preg_match_all("/function_exists\\('([A-Za-z_][A-Za-z0-9_]*)'\\)/", (string) file_get_contents($helpers), $matches);
                $names = [...$names, ...array_map(strtolower(...), $matches[1])];
            }
        }

        $names = array_values(array_unique([...$names, ...self::VAR_DUMPER]));
        sort($names);

        return $names;
    }

    /**
     * `dump()` y `dd()` no estan en ningun `helpers.php` de Laravel: los declara
     * `symfony/var-dumper`, que el framework arrastra. En un caso de uso son lo
     * mismo que un helper —y peor: escriben en la salida y `dd()` termina el
     * proceso—, asi que se vigilan junto a los del framework (V1-AR-1).
     */
    private const array VAR_DUMPER = ['dd', 'dump'];

    /**
     * Funciones de PHP que reciben un callable. Un helper pasado como texto a
     * una de ellas (`call_user_func('config', …)`, `array_map('now', …)`) es una
     * llamada al helper aunque su nombre no vaya seguido de `(` (V1-AR-1).
     */
    private const array CALLABLE_TAKERS = [
        'array_filter', 'array_map', 'array_reduce', 'array_walk', 'array_walk_recursive',
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
        'is_callable', 'iterator_apply', 'register_shutdown_function', 'uasort', 'uksort', 'usort',
    ];

    /**
     * Las llamadas a cualquiera de esas funciones globales en un fuente PHP.
     *
     * Una llamada es un nombre seguido de `(` que no va detras de `->`, `?->`,
     * `::`, `function`, `new` ni `const`. El nombre cuenta igual con o sin la
     * barra inicial: `\now()` y `now()` llaman al mismo helper. Cuentan tambien
     * las formas indirectas (V1-AR-1):
     *
     *   - un alias de importacion: `use function config as ajuste; ajuste()`, o
     *     `use function Illuminate\Support\now as reloj; reloj()`;
     *   - el nombre como texto en una funcion que recibe un callable
     *     ({@see self::CALLABLE_TAKERS}) o en `Closure::fromCallable()`.
     *
     * LO QUE NO VE, Y ESTA ESCRITO: una funcion variable (`$f = 'config';
     * $f()`). Por tokens no se distingue de invocar un cierre (`$act()`), que es
     * corriente en Application, y seguir el valor de la variable es analisis de
     * flujo, no de tokens. El texto `'config'` suelto tampoco se marca: seria
     * marcar cada clave de traduccion o de array que se llame igual que un
     * helper. Deptrac cubre la mitad que importa —el `use function` de una
     * funcion de `Illuminate\` en Application es una violacion de capa—, y la
     * revision de codigo, el resto.
     *
     * @param  list<string>  $names  En minusculas, como las da {@see self::names()}.
     * @return list<array{function: string, line: int}>
     */
    public static function callsIn(string $source, array $names): array
    {
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => ! \is_array($token) || ! \in_array($token[0], $ignored, true),
        ));

        $aliases = self::functionAliases($source);
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (! \is_array($token)) {
                continue;
            }

            $function = self::globalFunctionCalledAt($tokens, $i);
            $helper = self::helperName($aliases[$function] ?? $function);

            if (\in_array($helper, $names, true)) {
                $calls[] = ['function' => $helper, 'line' => $token[2]];
            }

            if (\in_array($function, self::CALLABLE_TAKERS, true) || self::isFromCallable($tokens, $i)) {
                $calls = [...$calls, ...self::helpersPassedAsText($tokens, $i + 1, $names)];
            }
        }

        return $calls;
    }

    /**
     * Las funciones importadas con `use function`, del nombre con el que se
     * llaman (el alias o el ultimo segmento) al nombre completo, todo en
     * minusculas y sin barra inicial. Admite la forma agrupada
     * `use function A\{b, c as d};`.
     *
     * @return array<string, string>
     */
    private static function functionAliases(string $source): array
    {
        preg_match_all('/\buse\s+function\s+([^;]+);/i', self::codeOnly($source), $matches);
        $aliases = [];

        foreach ($matches[1] as $clause) {
            $prefix = '';

            // use function A\B\{c, d as e};
            if (preg_match('/^(.*?)\\\\?\{(.+)\}$/s', trim($clause), $group) === 1) {
                $prefix = rtrim($group[1], '\\').'\\';
                $clause = $group[2];
            }

            foreach (explode(',', $clause) as $leaf) {
                $parts = preg_split('/\s+as\s+/i', trim($leaf)) ?: [];
                $aliases += self::aliasOf($prefix.($parts[0] ?? ''), $parts[1] ?? null);
            }
        }

        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    private static function aliasOf(string $name, ?string $alias): array
    {
        $full = strtolower(ltrim(trim($name), '\\'));
        $segments = explode('\\', $full);

        return $full === '' ? [] : [strtolower(trim($alias ?? end($segments))) => $full];
    }

    /**
     * El helper al que apunta un nombre de funcion ya resuelto: el propio nombre
     * si es global, y el ultimo segmento si es una funcion de `Illuminate\`.
     * Cualquier otra funcion con espacio de nombres no es un helper.
     */
    private static function helperName(string $function): string
    {
        if (! str_contains($function, '\\')) {
            return $function;
        }

        $segments = explode('\\', $function);

        return str_starts_with($function, 'illuminate\\') ? end($segments) : '';
    }

    /**
     * Si en la posicion `$i` empieza `Closure::fromCallable(`.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function isFromCallable(array $tokens, int $i): bool
    {
        $class = $tokens[$i];
        $method = $tokens[$i + 2] ?? null;

        return \is_array($class)
            && strtolower(ltrim($class[1], '\\')) === 'closure'
            && is_array($tokens[$i + 1] ?? null)
            && $tokens[$i + 1][0] === T_DOUBLE_COLON
            && \is_array($method)
            && strtolower($method[1]) === 'fromcallable';
    }

    /**
     * Los helpers escritos como texto en los argumentos de primer nivel de la
     * llamada que empieza en `$from` (el nombre de la funcion o de la clase).
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  list<string>  $names
     * @return list<array{function: string, line: int}>
     */
    private static function helpersPassedAsText(array $tokens, int $from, array $names): array
    {
        $found = [];
        $depth = 0;
        $count = \count($tokens);

        for ($j = $from; $j < $count; $j++) {
            $token = $tokens[$j];
            $depth += self::depthChange($token);
            $found[] = $depth === 1 ? self::helperWrittenAsText($token, $names) : null;

            if ($depth <= 0 && \in_array($token, [')', ';'], true)) {
                break;
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private static function depthChange(array|string $token): int
    {
        return match ($token) {
            '(', '[', '{' => 1,
            ')', ']', '}' => -1,
            default => 0,
        };
    }

    /**
     * El helper que nombra un texto literal (`'config'`, `'\now'`), o `null`.
     *
     * @param  array{0: int, 1: string, 2: int}|string  $token
     * @param  list<string>  $names
     * @return array{function: string, line: int}|null
     */
    private static function helperWrittenAsText(array|string $token, array $names): ?array
    {
        if (! \is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $text = strtolower(ltrim(substr($token[1], 1, -1), '\\'));

        return \in_array($text, $names, true) ? ['function' => $text, 'line' => $token[2]] : null;
    }

    /**
     * El nombre en minusculas de la funcion global que se llama en la posicion
     * `$i`, o cadena vacia si ahi no hay una llamada a funcion global.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens  Sin espacios ni comentarios.
     */
    private static function globalFunctionCalledAt(array $tokens, int $i): string
    {
        $token = $tokens[$i];
        $previous = $tokens[$i - 1] ?? null;

        $isName = \is_array($token) && \in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true);
        $isCalled = ($tokens[$i + 1] ?? null) === '(';
        $isMember = \is_array($previous)
            && \in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true);

        return $isName && $isCalled && ! $isMember ? strtolower(ltrim($token[1], '\\')) : '';
    }

    /**
     * Los nombres cortos de las facades que Laravel registra como alias globales
     * (`Cache`, `DB`, `Log`...): los que se pueden usar como `\Cache::get()` o
     * con un `use Cache;` sin nombrar `Illuminate\Support\Facades`.
     *
     * @return list<string>
     */
    public static function facadeAliases(): array
    {
        /** @var array<string, class-string> $aliases */
        $aliases = Facade::defaultAliases()->all();

        $names = array_keys($aliases);
        sort($names);

        return $names;
    }

    /**
     * Los usos de una facade en un fuente PHP: el import de
     * `Illuminate\Support\Facades\*`, una facade en tiempo real
     * (`Facades\App\…`), el import de un alias global o su uso calificado
     * (`\Cache::`, `\Illuminate\Support\Facades\Cache::`).
     *
     * @param  list<string>  $imports  Los `use` del fichero, como los da {@see ModuleTree::importsOf()}.
     * @param  list<string>  $aliases
     * @return list<string>
     */
    public static function facadeUsesIn(string $source, array $imports, array $aliases): array
    {
        $uses = [];

        foreach ($imports as $import) {
            $facade = str_starts_with($import, 'Illuminate\\Support\\Facades\\')
                || str_starts_with($import, 'Facades\\')
                || \in_array($import, $aliases, true);

            $uses[] = $facade ? 'importa '.$import : null;
        }

        $qualified = '/(?<![\w\\\\])\\\\(Illuminate\\\\Support\\\\Facades\\\\\w+|'.implode('|', array_map(preg_quote(...), $aliases)).')::/';
        preg_match_all($qualified, self::codeOnly($source), $matches);

        foreach ($matches[1] as $alias) {
            $uses[] = 'usa \\'.$alias.'::';
        }

        return array_values(array_unique(array_filter($uses)));
    }

    /**
     * El fuente sin comentarios ni cadenas, para que un docblock que hable de
     * `\Cache::` no cuente como uso.
     */
    private static function codeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            $skip = \is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true);
            $code .= $skip ? ' ' : (\is_array($token) ? $token[1] : $token);
        }

        return $code;
    }
}
