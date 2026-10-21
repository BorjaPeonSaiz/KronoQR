<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use RuntimeException;

/**
 * El paquete de diagnostico con datos personales pedido por consola no tiene
 * quien lo firme: falta la cuenta, o la que se dio ya no es una cuenta `admin`
 * activa (RL-19, PR17; doc 01 §7.2, «Firmas por consola»).
 *
 * Se lanza antes de recolectar nada: no se genera ni se escribe ningun
 * asiento. El mensaje no lleva la cuenta (regla dura 21).
 */
final class DiagnosticsResponsibleNotAllowed extends RuntimeException implements BusinessRejection
{
    public static function missing(): self
    {
        return new self('A console diagnostics bundle with personal data needs the admin account that has signed it.');
    }

    public static function notAnActiveAdmin(): self
    {
        return new self('The account given for the diagnostics bundle with personal data is no longer an active admin.');
    }
}
