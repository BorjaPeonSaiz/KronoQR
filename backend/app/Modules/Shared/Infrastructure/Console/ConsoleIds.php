<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Console;

/**
 * Un identificador escrito en una opcion de consola (`--acknowledge=42`), leido
 * sin adivinar.
 *
 * `(int)` se tragaria `12abc` como 12 y revisaria el asiento equivocado: un id
 * es un entero positivo escrito tal cual, sin signo ni decimales, o no es un
 * id. Lo usa `compliance:reconcile-work-record --acknowledge`, cuyo objetivo es
 * el id del asiento de `audit_log`. **La cuenta que firma ya no se nombra por su
 * id** desde la 2.3.0: `--responsible` es su uuid o su correo
 * ({@see ConsoleResponsible}).
 */
final class ConsoleIds
{
    /** Cifras significativas que caben en un `BIGINT` sin desbordar. */
    private const int MAX_DIGITS = 18;

    /** El entero positivo que dice el texto, sin espacios alrededor, o `null` si no es solo eso. */
    public static function positive(string $value): ?int
    {
        $value = trim($value);
        $significant = ltrim($value, '0');

        if (! ctype_digit($value) || $significant === '' || \strlen($significant) > self::MAX_DIGITS) {
            return null;
        }

        return (int) $value;
    }
}
