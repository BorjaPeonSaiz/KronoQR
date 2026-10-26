<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Generation;

use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportCriteriaNarrator;
use App\Modules\Reporting\Application\Port\ReportExportDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\Support\ReportDataset;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;
use RuntimeException;

/**
 * El informe por periodo y la nomina en diferido (**RF-IN-06**, **RF-IN-07**).
 *
 * ## No calcula nada nuevo: es **la misma** consulta del panel
 *
 * Llama a {@see GeneratePeriodReport}, el mismo caso de uso que sirve
 * `GET /reports/period` y su descarga. Si tuviera una consulta propia, el
 * fichero que alguien recibe por correo y la tabla que estaba mirando podrian
 * discrepar — y el que se creeria seria el equivocado.
 *
 * Lo unico que cambia son **los dos techos sincronos**: aqui no aplican, y por
 * eso entran como `PHP_INT_MAX`. El techo que **si** sigue en pie es
 * `DateRange::MAXIMUM_DAYS` (366), y el `statement_timeout` se sustituye por el
 * suyo (`REPORTING_EXPORT_TIMEOUT_SECONDS`): lo pone el lector que el proveedor
 * del modulo construye para este camino (RNF-P-02, regla dura 19).
 *
 * ## El alcance se aplica tal cual, nunca se recalcula
 *
 * Sale de la columna `scope`, que es la instantanea del momento en que se
 * autorizo.
 *
 * ## `compose()` y no `handle()`
 *
 * El asiento `personal_data.accessed` NO se escribe aqui, en el trabajo y a
 * nombre de `system`, sino al cerrar, a nombre de quien lo pidio. Si la
 * generacion falla, no queda asiento de una lectura que no llego a nadie.
 *
 * ## Dos fases sin porcentaje
 *
 * La consulta es una sola sentencia y el escritor no cuenta mientras escribe:
 * no hay un total que medir sin inventarlo. La cancelacion se comprueba entre
 * una fase y otra; la consulta que ya esta en marcha no se interrumpe —la corta,
 * si acaso, su propio `statement_timeout`— y su resultado se descarta.
 */
final readonly class PeriodReportExportGeneration implements ReportExportGeneration
{
    /**
     * Hasta cuantos afectados se enumeran en el asiento. La misma cifra que
     * `GeneratePeriodReport`, y por el mismo motivo: por encima, el recuento y
     * el alcance contestan mejor que quinientos identificadores, y enumerarlos
     * convertiria el trail —cuatro años (RL-02)— en una copia de la plantilla.
     */
    private const int MAX_ENUMERATED_SUBJECTS = 50;

    public function __construct(
        /** El **mismo** caso de uso que el informe sincrono, con el lector en diferido. */
        private GeneratePeriodReport $reports,
        private ReportExportDocumentWriter $documents,
        private PayrollDocumentWriter $payroll,
        private ReportCriteriaNarrator $criteria,
        private ReportExportStorage $storage,
    ) {}

    public function generate(ReportExportRun $run): GeneratedReportFile
    {
        $export = $run->export;

        /*
         * El alcance no puede faltar en una fila en curso: solo se borra al
         * purgar (RL-11), y purgar exige `completed`. Se comprueba igualmente
         * porque generar un informe **sin alcance** seria generarlo sobre la
         * plantilla entera, que es exactamente la fuga que RF-ID-03 existe para
         * impedir: ante la duda, no se genera.
         */
        $scope = $export->scope
            ?? throw new RuntimeException('El informe en diferido no tiene alcance que aplicar: '.$export->uuid);

        $run->progress->enter(ReportExportStage::Reading);

        $composed = $this->reports->compose(
            $export->parameters->toQuery($scope),
            maxRangeDays: PHP_INT_MAX,
            maxRows: PHP_INT_MAX,
            delivery: ReportDelivery::tryFrom($export->format) ?? ReportDelivery::Csv,
            // `payroll_export` cuando el fichero es el de la nomina, y el del
            // informe por periodo en el resto: el mismo campo que escribe la
            // descarga sincrona de cada uno (RS-05).
            dataset: $export->kind === ReportExportKind::Payroll
                ? ReportDataset::PayrollExport
                : ReportDataset::PeriodReport,
        );
        $report = $composed->report;

        $run->progress->enter(ReportExportStage::Writing);

        $fileName = $this->fileNameFor($export, $report);
        $path = $run->claim($this->storage->pathFor($export->uuid, $fileName));
        $rowCount = $this->write($export, $report, $path);

        return new GeneratedReportFile(
            fileName: $fileName,
            path: $path,
            rowCount: $rowCount,
            criteria: $this->criteria->linesFor($report),
            employeeUuids: self::affectedSubjects($report),
            disclosureDataset: $composed->dataset->value,
            disclosureRecordCount: $composed->recordCount(),
            disclosureContext: $composed->disclosure,
        );
    }

    /**
     * La lista de afectados, **solo cuando el conjunto es pequeño**.
     *
     * Con agrupacion por departamento o por centro viene vacia del propio
     * informe, y es correcto: ahi no se ha divulgado el dato de nadie en
     * particular. Identificadores, nunca nombres (regla dura 21).
     *
     * @return list<string>
     */
    private static function affectedSubjects(PeriodReport $report): array
    {
        $uuids = $report->employeeUuids();

        return \count($uuids) > self::MAX_ENUMERATED_SUBJECTS ? [] : $uuids;
    }

    private function write(ReportExport $export, PeriodReport $report, string $path): int
    {
        return $export->kind === ReportExportKind::Payroll
            ? $this->payroll->write($export, $report, $path)
            : $this->documents->write($export, $report, $path);
    }

    private function fileNameFor(ReportExport $export, PeriodReport $report): string
    {
        return $export->kind === ReportExportKind::Payroll
            ? $this->payroll->fileNameFor($export, $report)
            : $this->documents->fileNameFor($export, $report);
    }
}
