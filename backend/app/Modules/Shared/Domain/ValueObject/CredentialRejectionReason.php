<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Por que no se ha podido resolver una credencial. Lo decide Identity, que es
 * quien tiene la tabla `credentials` y la clave de firma.
 *
 * En Shared porque viaja dentro de {@see CredentialResolution}, que cruza la
 * frontera de Identity a Attendance (ADR-025, restriccion 2).
 *
 * **Este motivo no sale nunca por la API.** RS-03 y la regla dura 17 obligan a
 * que el rechazo que ve el quiosco sea generico y de tiempo constante: no se
 * revela si el codigo no existe, esta revocado o tiene mala firma. El motivo
 * existe para `scan_events.result` y para las metricas —doc 01 §11 exige poder
 * contar los rechazos por firma—, y se queda del lado del servidor.
 */
enum CredentialRejectionReason: string
{
    /** La firma es valida pero el token no corresponde a ninguna credencial. */
    case UNKNOWN = 'unknown';

    /** La credencial existe y esta revocada: perdida, robo, deterioro o baja (RF-QR-03, RN-14). */
    case REVOKED = 'revoked';

    /** La firma HMAC no verifica, o el `key_id` no es una clave vigente (RF-QR-02). */
    case INVALID_SIGNATURE = 'invalid_signature';
}
