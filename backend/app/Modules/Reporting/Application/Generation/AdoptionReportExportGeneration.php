<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Generation;

use App\Modules\Reporting\Application\Port\AdoptionReportFileWriter;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\Query\AdoptionReportCriteria;
use App\Modules\Reporting\Application\Query\ReadAdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;

/**
 * El cuadro de impacto y adopcion en diferido (**RF-IN-08**, V5-PA-4).
 *
 * ## El mismo cuadro que la descarga sincrona
 *
 * {@see ReadAdoptionReport} con los mismos criterios, y el mismo documento
 * —criterios, huella del contenido y, en PDF, sello— por
 * {@see AdoptionReportFileWriter}. Lo que cambia son los techos: el de rango
 * sincrono de `reporting.adoption.max_range_days` no aplica y queda el del
 * calendario, `DateRange::MAXIMUM_DAYS`; y el PDF espera su hueco en el embudo
 * de Chromium lo que puede esperar un trabajo de cola, no una persona.
 *
 * ## Sin datos personales, y por eso sin `personal_data.accessed`
 *
 * Doce agregados de la instalacion, sin un `employee_uuid` (regla dura 21). Lo
 * que deja asiento es que salio **un documento**: el mismo
 * `adoption_report.exported` que la descarga sincrona, a nombre de quien lo
 * pidio, que el cierre escribe con `adoptionPeriod`.
 */
final readonly class AdoptionReportExportGeneration implements ReportExportGeneration
{
    public function __construct(
        /** Construido con el lector del `statement_timeout` en diferido. */
        private ReadAdoptionReport $reports,
        private AdoptionReportFileWriter $documents,
        private ReportExportStorage $storage,
    ) {}

    public function generate(ReportExportRun $run): GeneratedReportFile
    {
        $export = $run->export;

        $run->progress->enter(ReportExportStage::Reading);

        $report = $this->reports->handle(
            new AdoptionReportCriteria(from: $export->parameters->from, to: $export->parameters->to),
            maxRangeDays: DateRange::MAXIMUM_DAYS,
            maxRows: PHP_INT_MAX,
        );

        $run->progress->enter(ReportExportStage::Writing);

        $fileName = $this->documents->fileNameFor($report, $export->format);
        $path = $run->claim($this->storage->pathFor($export->uuid, $fileName));
        $written = $this->documents->write($report, $export->format, $path, $export->requestedByUuid);

        return new GeneratedReportFile(
            fileName: $fileName,
            path: $path,
            rowCount: $written->rowCount,
            criteria: $written->criteria,
            adoptionPeriod: [
                'from' => $report->range->isoFrom(),
                'to' => $report->range->isoTo(),
                // La huella del CONTENIDO, la del pie del PDF: la misma que deja la
                // descarga sincrona en `adoption_report.exported`.
                'content_digest' => $written->contentDigest,
            ],
        );
    }
}
