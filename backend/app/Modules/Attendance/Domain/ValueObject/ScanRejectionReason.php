<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;

/**
 * Por que un escaneo no produjo tramo. Sus valores respaldados son los de
 * `scan_events.result` (doc 01 §5.5).
 *
 * **Nunca sale por la API.** RS-03 y la regla dura 17 obligan a que el rechazo
 * que ve el quiosco sea generico y de tiempo constante: no se revela si el
 * codigo no existe, esta revocado o tiene mala firma. Este motivo existe para
 * el registro interno y para las metricas —doc 01 §11 exige poder contar los
 * rechazos por firma— y se queda del lado del servidor.
 *
 * `DEBOUNCE` esta aqui, junto a los otros tres, porque el escaneo se registra
 * con `result = rejected_debounce`. Ahora bien, **la respuesta HTTP es un 200
 * con `action: debounced`** (ADR-031): el escaneo se entendio y se proceso, y
 * lo que el servidor decidio es que el estado correcto ya era el que habia.
 * Devolverlo como error dejaria la cola offline reintentando contra una ventana
 * que ya paso.
 */
enum ScanRejectionReason: string
{
    case UNKNOWN_CREDENTIAL = 'rejected_unknown';
    case REVOKED_CREDENTIAL = 'rejected_revoked';
    case INVALID_SIGNATURE = 'rejected_signature';
    case DEBOUNCE = 'rejected_debounce';

    /**
     * Traduce el motivo con el que Identity rechazo la credencial.
     *
     * Son dos vocabularios y no uno a proposito: el de Identity describe **la
     * credencial** y el de aqui describe **el escaneo**, que tiene un motivo
     * mas —el anti-rebote— que a Identity no le incumbe. Anadir un desenlace de
     * escaneo no obliga a tocar el otro modulo.
     */
    public static function fromCredentialRejection(CredentialRejectionReason $reason): self
    {
        return match ($reason) {
            CredentialRejectionReason::UNKNOWN => self::UNKNOWN_CREDENTIAL,
            CredentialRejectionReason::REVOKED => self::REVOKED_CREDENTIAL,
            CredentialRejectionReason::INVALID_SIGNATURE => self::INVALID_SIGNATURE,
        };
    }
}
