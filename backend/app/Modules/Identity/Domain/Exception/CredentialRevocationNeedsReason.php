<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Una revocacion sin motivo (RF-QR-03, regla dura 6).
 *
 * El motivo no es burocracia: distingue «se perdio antes de entregarla» de «el
 * empleado la perdio» de «se revoco por baja», y son incidencias distintas con
 * consecuencias distintas. Acompana a la entrada de `audit_log` y es lo que
 * permite explicar meses despues por que una persona dejo de poder fichar.
 *
 * La base de datos declara la misma invariante
 * (`credentials_chk_revocation_has_reason`): en un sistema con valor probatorio,
 * las reglas que importan no dependen solo del codigo de aplicacion.
 */
final class CredentialRevocationNeedsReason extends IdentityDomainException implements BusinessRejection
{
    public static function make(): self
    {
        return new self('Una revocacion de credencial tiene que declarar su motivo.');
    }
}
