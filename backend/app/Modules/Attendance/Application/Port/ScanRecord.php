<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;

/**
 * La fila de `scan_events` que el caso de uso quiere dejar escrita, ya
 * decidida.
 *
 * Es un DTO del puerto {@see ScanLog} y no un modelo Eloquent: el caso de uso
 * describe **que ocurrio** y el adaptador decide como se guarda. Vive en
 * `Application/Port/` porque es el lenguaje de esa interfaz, y una interfaz no
 * puede hablar en tipos de `Application/` sin invertir la direccion de las
 * dependencias (ADR-025, restriccion 2).
 *
 * **Todo escaneo escribe una fila, se acepte o no** (doc 01 §5.5). La fila del
 * rechazo es la que permite investigar despues sin haber revelado nada en su
 * momento, y la que alimenta el contador de rechazos por firma que exige el
 * doc 01 §11.
 *
 * **Las dos marcas de tiempo, siempre** (regla dura 9, RF-AT-09):
 * `occurredAt` es el momento real medido por el reloj del dispositivo y puede
 * venir de la cola offline con dias de retraso; `recordedAt` es la recepcion en
 * servidor. El registro legal usa `occurredAt`.
 *
 * **Sin PII** (regla dura 21): el empleado viaja por su UUID, del payload solo
 * se guarda su huella —nunca el payload, que es lo que esta impreso en la
 * tarjeta— y `clientMeta` lleva version de la app y modelo de tablet, jamas un
 * nombre.
 */
final readonly class ScanRecord
{
    /**
     * @param  string  $scanId  UUID v7 generado en el cliente. Es la clave de idempotencia
     *                          (regla dura 8) y lo garantiza el UNIQUE de la columna.
     * @param  int  $deviceId  Quiosco desde el que se escaneo (`devices.id`).
     * @param  string|null  $employeeUuid  Nulo cuando la credencial no resolvio: un escaneo
     *                                     desconocido o mal firmado se registra igual.
     * @param  string|null  $shiftEntryUuid  El tramo que este escaneo abrio o cerro. Nulo en
     *                                       todo rechazo, incluido el anti-rebote
     *                                       (`scan_events_chk_rejected_has_no_shift_entry`).
     * @param  int|null  $clockSkewSeconds  `recordedAt - occurredAt`, con signo (RF-AT-10). El
     *                                      desfase **nunca rechaza el fichaje** (regla dura 19):
     *                                      se registra para que la incidencia de la tarea 3.5
     *                                      pueda construirse hacia atras.
     * @param  bool  $flaggedForReview  El fichaje pide validacion humana por su origen (RF-AT-11:
     *                                  el PIN) o por su desfase (RN-15: el retraso supera el
     *                                  umbral de la instalacion). Lo decide `ReviewPolicy` en el
     *                                  dominio; la bandeja que lo trabaja es de la 2.5/3.5.
     * @param  int|null  $workedMinutes  El acumulado de la jornada tal y como este escaneo lo
     *                                   dejo, no como este HOY (regla dura 8): un reenvio tiene
     *                                   que devolver el mismo numero, y recalcularlo desde
     *                                   `shift_entries` en el reenvio da otra cifra en cuanto un
     *                                   escaneo posterior del mismo dia cambia el tramo. Nulo
     *                                   solo en un rechazo real; presente en el anti-rebote.
     * @param  array<string, scalar>  $clientMeta  Telemetria del cliente. Nunca datos personales.
     */
    public function __construct(
        public string $scanId,
        public int $deviceId,
        public ?string $employeeUuid,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $recordedAt,
        public ScanOrigin $origin,
        public ScanIntent $intent,
        public ScanResult $result,
        public ?string $shiftEntryUuid = null,
        public ?string $payloadFingerprint = null,
        public ?int $clockSkewSeconds = null,
        public bool $flaggedForReview = false,
        public ?int $workedMinutes = null,
        public array $clientMeta = [],
    ) {}
}
