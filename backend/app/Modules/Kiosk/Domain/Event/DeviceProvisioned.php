<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Un quiosco ha quedado dado de alta —o reactivado— al confirmarse su codigo de
 * emparejamiento (**RF-PD-06**, regla dura 6, RL-04).
 *
 * ## Por que este hecho se audita y no el `claim`
 *
 * Porque **este es el acto con actor**. Los tres pasos del emparejamiento son
 * uno solo desde el punto de vista de la responsabilidad: la tablet pide y
 * recoge de forma anonima —no tiene sesion, no puede tenerla— y la unica persona
 * que decide algo es quien teclea el codigo en el panel. Auditar el `claim`
 * habria dejado el asiento del alta de un quiosco con actor `system`, es decir,
 * sin nadie a quien preguntar seis meses despues.
 *
 * La emision del token si queda registrada aparte, cuando la tablet lo recoge:
 * `Identity` publica `DeviceTokenIssued` y `Compliance` lo sella como
 * `device.paired`. Son dos hechos distintos —«se dio de alta el puesto» y «se
 * entrego una llave»— y separarlos es lo que permite ver que entre uno y otro
 * pasaron ocho minutos, o que nunca llego a pasar nada.
 *
 * ## `reactivated` y `previousStatus`, y por que los dos
 *
 * Sustituir la tablet averiada de Recepcion reactiva la fila existente con el
 * mismo `uuid` (ADR-028): el quiosco de Recepcion sigue siendo el quiosco de
 * Recepcion. Quien lea el trail necesita distinguir «se instalo un puesto nuevo»
 * de «se cambio el aparato del puesto de siempre», porque la segunda frase
 * explica por que hay un hueco en los fichajes de ese quiosco y la primera no.
 *
 * `previousStatus` es lo que hace el asiento reconstruible: dice contra que se
 * comparo. `reactivated` se podria derivar de el, y se manda igual porque el
 * asiento se lee, no se calcula.
 *
 * ## Sin datos personales (reglas duras 10 y 21)
 *
 * El nombre es el del **sitio** —«Recepcion»—, no el de nadie. Y el actor viaja
 * como identificador de cuenta, nunca como nombre: `audit_log` con nombres en
 * claro es un directorio de plantilla exportable.
 */
final readonly class DeviceProvisioned implements DomainEvent
{
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        /** Nombre del quiosco, que es el del sitio (regla dura 21). */
        public string $name,
        /** `pairing_id` de la solicitud que se confirmo, para poder unir los tres pasos. */
        public string $pairingRequestUuid,
        /** Si se reactivo una fila revocada en lugar de crear una nueva (ADR-028). */
        public bool $reactivated,
        /** `active`, `revoked` o `null` si el puesto no existia. */
        public ?string $previousStatus,
        /**
         * Quien lo confirmo, o `null` desde `kiosk:pairing-code`, que no tiene
         * sesion. El asiento lo traduce a actor `system`: atribuirselo a la
         * ultima persona que entro al panel seria falsificar el trail.
         */
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Nombre estable, no derivado de la clase (doc 02 §1.6): renombrar una clase
     * no puede cambiar lo que ya esta escrito en un registro con valor legal.
     */
    public function eventName(): string
    {
        return 'kiosk.device_provisioned';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
