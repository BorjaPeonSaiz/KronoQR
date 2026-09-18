<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Model\WorkDay;
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
     * RN-18, el **fichaje irreconciliable**: el escaneo ocurrio de verdad y lo
     * que no se puede es derivar de el un tramo. Son **dos situaciones con dos
     * limites opuestos**, y las resuelve {@see WorkDay::outOfOrderScanFor()}: al
     * cerrar, su `occurred_at` no es posterior a la entrada del turno abierto
     * (RN-03, y la igualdad tambien lo es); al abrir, el tramo que crearia
     * pisaria a uno ya cerrado (RN-02, con limites `[inicio, fin)`: entrar a la
     * hora exacta en que se salio si cuadra).
     *
     * **Es el unico motivo que no habla de la credencial**, y esa diferencia se
     * nota en el registro y no en la respuesta: la fila queda **marcada para
     * revision** y la revision diaria abre una incidencia con ella, mientras que
     * hacia fuera viaja el mismo `422` generico que los demas (RS-03, regla dura
     * 17). Distinguirlo en la respuesta convertiria el quiosco en un oraculo
     * sobre la jornada de otra persona.
     */
    case OUT_OF_ORDER = 'rejected_out_of_order';

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
