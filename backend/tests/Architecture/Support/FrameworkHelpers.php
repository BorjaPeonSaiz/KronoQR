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

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Las llamadas a cualquiera de esas funciones globales en un fuente PHP.
     *
     * Una llamada es un nombre seguido de `(` que no va detras de `->`, `?->`,
     * `::`, `function`, `new` ni `const`. El nombre cuenta igual con o sin la
     * barra inicial: `\now()` y `now()` llaman al mismo helper.
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

        $calls = [];

        foreach ($tokens as $i => $token) {
            $function = self::globalFunctionCalledAt($tokens, $i);

            if (\is_array($token) && \in_array($function, $names, true)) {
                $calls[] = ['function' => $function, 'line' => $token[2]];
            }
        }

        return $calls;
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
