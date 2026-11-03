<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use RuntimeException;

/**
 * Quien firma la exportacion legal pedida por consola ya no es una cuenta de
 * gestion activa con rol `admin`, `rrhh` o `auditor` (RF-IN-05, RL-04; doc 01
 * §7.2, «Firmas por consola»).
 *
 * Se comprueba antes de leer el registro y otra vez con la cadena de
 * `audit_log` tomada, justo antes del asiento: una baja entre la pregunta de la
 * consola y la firma no deja una exportacion a nombre de quien ya no responde.
 * No se escribe ningun asiento. La consola sale con `2`.
 *
 * {@see self::$fileWritten} dice si el fichero llego a escribirse —la baja
 * llego durante la escritura—: quien eligio la ruta tiene que borrarlo, porque
 * un registro horario nominal sin asiento no se entrega. El mensaje no lleva
 * la cuenta (regla dura 21).
 */
final class LegalExportSignerNotAllowed extends RuntimeException implements BusinessRejection
{
    private function __construct(public readonly bool $fileWritten)
    {
        parent::__construct('The account given for the legal export is no longer an active management account that can sign it.');
    }

    public static function beforeWriting(): self
    {
        return new self(fileWritten: false);
    }

    public static function afterWriting(): self
    {
        return new self(fileWritten: true);
    }
}
