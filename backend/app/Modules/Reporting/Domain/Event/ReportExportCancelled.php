<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Quien pidio una exportacion la detuvo antes de que terminara (**V5-PA-4**,
 * RS-05).
 *
 * Deja asiento `report_export.cancelled` a su nombre: una descarga de la
 * plantilla que se pidio y se paro sigue siendo una intencion que una revision
 * de accesos tiene que poder ver, y sin este hecho la fila `cancelled` no
 * tendria quien la explicara en el trail. **Sin parametros ni nombres**: los
 * parametros ya estan en `report_export.requested`, con el mismo `uuid`.
 */
final readonly class ReportExportCancelled implements DomainEvent
{
    public function __construct(
        public string $uuid,
        public string $kind,
        public string $format,
        /** `pending` o `running`: si llego a empezar a leer el registro. */
        public string $previousStatus,
        public int $requestedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'reporting.report_export_cancelled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
