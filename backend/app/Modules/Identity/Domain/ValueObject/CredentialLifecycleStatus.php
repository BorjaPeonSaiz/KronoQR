<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Identity\Domain\Model\Credential;

/**
 * Los cinco estados del panel de credenciales (RF-QR-08).
 *
 * El doc 02 §5.5 dice para que existe el panel: *«RF-QR-08 existe para que RRHH
 * vea de un vistazo quien no puede fichar todavia. Sin el, el problema se
 * descubre delante del quiosco a las 06:00.»*
 *
 * | Estado | Condicion |
 * |---|---|
 * | `no_credential` | El empleado no tiene ninguna fila |
 * | `pending_print` | `revoked_at IS NULL AND printed_at IS NULL` |
 * | `pending_delivery` | `revoked_at IS NULL AND printed_at IS NOT NULL AND delivered_at IS NULL` |
 * | `delivered` | `revoked_at IS NULL AND delivered_at IS NOT NULL` |
 * | `revoked` | No queda ninguna fila sin revocar, y hay al menos una revocada |
 *
 * **Derivados, sin columna `status`.** Es la misma decision que en el agregado
 * {@see Credential}: un estado almacenado y otro derivado acaban discrepando, y
 * aqui discrepar significa que RRHH da por entregada una tarjeta que nadie tiene.
 *
 * **`revoked` y `no_credential` se separan a proposito.** El enunciado de la
 * tarea define «sin credencial» como *«el empleado no tiene ninguna fila no
 * revocada»*, lo que mete en el mismo saco a quien nunca tuvo tarjeta y a quien
 * la tuvo y se le retiro. Las dos personas no pueden fichar, y las dos cuentan
 * igual en las metricas —ver {@see canClockWithCard()}—, pero para quien tiene
 * que resolverlo son dos situaciones distintas: la primera se arregla emitiendo,
 * la segunda es una reemision y probablemente una incidencia. Distinguirlas no
 * cambia ningun recuento y evita que el panel llame «sin credencial» a alguien
 * cuya tarjeta se revoco esta mañana.
 */
enum CredentialLifecycleStatus: string
{
    case NO_CREDENTIAL = 'no_credential';

    case PENDING_PRINT = 'pending_print';

    case PENDING_DELIVERY = 'pending_delivery';

    case DELIVERED = 'delivered';

    case REVOKED = 'revoked';

    /**
     * Deriva el estado de la credencial **vigente** de un empleado, o de la
     * ultima que tuvo si ya no le queda ninguna activa.
     *
     * `null` significa que no tiene ninguna fila en absoluto.
     */
    public static function of(?Credential $credential): self
    {
        if (! $credential instanceof Credential) {
            return self::NO_CREDENTIAL;
        }

        if (! $credential->isActive()) {
            return self::REVOKED;
        }

        if (! $credential->isPrinted()) {
            return self::PENDING_PRINT;
        }

        return $credential->isDelivered() ? self::DELIVERED : self::PENDING_DELIVERY;
    }

    /**
     * Si esta persona tiene ya una tarjeta en la mano con la que fichar.
     *
     * Es el complemento exacto de `employees_without_delivered_credential`
     * (doc 02 §8.2): *«cuenta a quienes estan de alta pero todavia no pueden
     * fichar. Debe llegar a cero antes del primer dia de cada incorporacion.»*
     *
     * **`pending_delivery` cuenta como «todavia no».** La tarjeta ya es
     * escaneable —esta impresa y no revocada—, pero sigue encima de una mesa de
     * RRHH y no en el bolsillo de nadie. La metrica mide el proceso completo, no
     * el estado de la base de datos: si se detuviera en la impresion, llegaria a
     * cero la tarde anterior a una incorporacion en la que nadie puede fichar.
     */
    public function canClockWithCard(): bool
    {
        return $this === self::DELIVERED;
    }

    /**
     * Si esta credencial esta esperando a pasar por la impresora.
     *
     * Es lo que cuenta `credentials_pending_print{site}` y lo que selecciona
     * `credentials:print-batch --pending`.
     */
    public function isPendingPrint(): bool
    {
        return $this === self::PENDING_PRINT;
    }
}
