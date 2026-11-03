<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use RuntimeException;

/**
 * La exportacion integra pedida por consola no tiene quien la firme: falta la
 * cuenta, o la que se dio ya no es una cuenta `admin` activa (RL-20, RS-05;
 * doc 01 §7.2, «Firmas por consola»).
 *
 * Se lanza antes de crear la fila: no se pide, no se genera ni se escribe
 * ningun asiento. La consola sale con `2`. El mensaje no lleva la cuenta
 * (regla dura 21).
 */
final class DataExportSignerNotAllowed extends RuntimeException implements BusinessRejection
{
    public static function missing(): self
    {
        return new self('A full data export requested from the console needs the admin account that has signed it.');
    }

    public static function notAnActiveAdmin(): self
    {
        return new self('The account given for the full data export is no longer an active admin.');
    }
}
