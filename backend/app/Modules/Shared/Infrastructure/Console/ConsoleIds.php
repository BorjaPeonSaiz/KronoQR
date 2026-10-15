<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Console;

/**
 * Un identificador escrito en una opcion de consola (`--responsible=7`,
 * `--acknowledge=42`), leido sin adivinar.
 *
 * `(int)` se tragaria `12abc` como 12 y firmaria con la cuenta equivocada: un
 * id es un entero positivo escrito tal cual, sin signo ni decimales, o no es un
 * id. Lo usan las acciones de consola que firman un asiento
 * (`compliance:reconcile-work-record --acknowledge`,
 * `workforce:correct-site-timezone`).
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
