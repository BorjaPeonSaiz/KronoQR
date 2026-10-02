<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Product\Domain\Event\DataExportFileMissing;
use App\Modules\Reporting\Domain\Event\ReportExportFileMissing;
use DateTimeInterface;
use DateTimeZone;

/**
 * Sella en `audit_log` que el fichero de una exportacion **desaparecio antes de
 * caducar** (RL-15, regla dura 6; ADR-045 §d, condicion C5).
 *
 * ## Un listener para las dos clases
 *
 * `data_export.file_missing` y `report_export.file_missing` son el mismo hecho
 * sobre dos ficheros distintos, con el mismo actor y el mismo payload. Cada uno
 * se queda en la familia de su prefijo —`legal_export` y `PersonalDataAccess`—,
 * que es donde lo buscara quien reconstruya que paso con esa exportacion.
 *
 * ## Actor `system` y payload de lista cerrada
 *
 * Lo detecta una tarea programada, no una persona. El payload lleva el `uuid` de
 * la exportacion, su `expires_at` y el momento de la deteccion, y **nunca la
 * ruta ni el nombre del fichero** (regla dura 21): el `uuid` basta para cruzarlo
 * con los asientos de generacion y descarga, que ya llevan el nombre.
 *
 * ## Sincrono, dentro de la transaccion de la purga
 *
 * El caso de uso publica con el candado de la cadena ya tomado y la fila recien
 * marcada `purged`. Si el asiento falla, la fila no cambia: un fichero que
 * desaparece no puede quedar `purged` sin su rastro (ADR-027).
 */
final readonly class RecordGeneratedFileMissing
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function dataExport(DataExportFileMissing $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::DataExportFileMissing,
            subject: AuditSubject::of('data_export'),
            payload: AuditPayload::of([
                'data_export_uuid' => $event->uuid,
                'expires_at' => $event->expiresAt,
                'detected_at' => self::utc($event),
            ]),
        ));
    }

    public function reportExport(ReportExportFileMissing $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::ReportExportFileMissing,
            subject: AuditSubject::of('report_export'),
            payload: AuditPayload::of([
                'report_export_uuid' => $event->uuid,
                'expires_at' => $event->expiresAt,
                'detected_at' => self::utc($event),
            ]),
        ));
    }

    private static function utc(DataExportFileMissing|ReportExportFileMissing $event): string
    {
        return $event->occurredAt()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
