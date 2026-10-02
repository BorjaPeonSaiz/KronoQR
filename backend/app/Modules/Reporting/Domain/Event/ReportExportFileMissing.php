<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El fichero de un informe en diferido ha desaparecido **antes de caducar**
 * (**RF-IN-06**, RL-15; ADR-045 §d, condicion C5).
 *
 * Lleva horas nominales de la plantilla: si deja de estar antes de su
 * `expires_at`, es un borrado a mano o una exfiltracion, y sin asiento no habria
 * forma de acotar cuando ocurrio. La purga diaria lo detecta, marca la fila
 * `purged` —minimizada, como cualquier purga— y lo publica; `Compliance` lo sella
 * como `report_export.file_missing`.
 *
 * El `uuid` y la caducidad; **nunca la ruta ni el nombre del fichero**, que lleva
 * el periodo (regla dura 21).
 */
final readonly class ReportExportFileMissing implements DomainEvent
{
    public function __construct(
        public string $uuid,
        /** RFC 3339 en UTC. */
        public string $expiresAt,
        private DateTimeImmutable $detectedAt,
    ) {}

    public function eventName(): string
    {
        return 'reporting.report_export_file_missing';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
