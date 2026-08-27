<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Un escaneo no ha producido tramo (doc 01 §5.4).
 *
 * A diferencia de los otros tres, **no lo emite `WorkDay`**: el escaneo ni
 * siquiera llega al agregado cuando la credencial no resuelve. Lo emite el caso
 * de uso de la tarea 1.4, y vive en el dominio porque el motivo y su vocabulario
 * son del nucleo, no del transporte.
 *
 * De el salen la fila de `scan_events` —que registra **todo** escaneo, se
 * acepte o no— y el contador de rechazos por firma que pide el documento 01
 * §11. **Lo que sale por la API es otra cosa**: un rechazo generico y de tiempo
 * constante que no distingue causa (RS-03, regla dura 17), salvo el anti-rebote,
 * que es un desenlace aceptado con `200` (ADR-031).
 *
 * `employeeUuid` es nulo casi siempre: si la credencial no resolvio, no hay
 * empleado que nombrar. Solo el anti-rebote lo conoce, porque ahi la credencial
 * es valida y acaba de funcionar hace segundos.
 */
final readonly class ScanRejected implements DomainEvent
{
    public function __construct(
        /** UUID v7 generado en el cliente; es la clave de idempotencia (regla dura 8). */
        public string $scanId,
        public ScanRejectionReason $reason,
        /** Momento real del escaneo, medido por el reloj del dispositivo (RF-AT-09). */
        public DateTimeImmutable $occurredAt,
        /** Solo se conoce cuando la credencial resolvio, es decir, en el anti-rebote. */
        public ?string $employeeUuid = null,
        /** Quiosco desde el que se escaneo; nulo en un origen sin dispositivo. */
        public ?int $deviceId = null,
        /** Huella del payload, para poder investigar sin guardar el payload (RS-03). */
        public ?string $payloadFingerprint = null,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.scan_rejected';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
