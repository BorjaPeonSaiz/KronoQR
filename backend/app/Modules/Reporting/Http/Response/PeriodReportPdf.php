<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Http\Support\PeriodReportLayout;
use Illuminate\Support\Facades\View;

/**
 * El informe por periodo como PDF **sellado** (**RF-IN-04**).
 *
 * ## Que lleva el sello y por que cuatro cosas y no una
 *
 * El pie se repite en **todas** las paginas —lo compone el motor, no el cuerpo
 * del documento— y dice:
 *
 *   1. **Cuando se genero**, en la zona del centro (ADR-040) y diciendo cual es.
 *      En UTC parecería generado por otro sistema.
 *   2. **Quien lo emitio**, por el nombre de la cuenta. Nunca su correo (regla
 *      dura 12: el producto no depende del correo de nadie) y nunca su `uuid`,
 *      que no le dice nada a quien lee un papel.
 *   3. **Que periodo abarca**, porque una hoja suelta fotocopiada del monton
 *      tiene que seguir diciendo de que mes habla.
 *   4. **La huella SHA-256 del contenido**, que es lo que convierte «este es el
 *      informe de marzo» en una afirmacion comprobable.
 *
 * ## La huella es del contenido, no del binario
 *
 * Esta escrito en `PeriodReportDigest`, de `Http/Support`, y aqui solo se
 * imprime. La consecuencia que hay que tener presente al leer un
 * PDF: **dos PDF del mismo informe generados en dos momentos distintos son dos
 * ficheros distintos byte a byte y llevan la misma huella**, porque el sello
 * temporal no entra en ella. Y al reves: si una correccion cambia una hora, la
 * huella cambia aunque el papel se parezca. Eso es exactamente lo que hace falta
 * para poder comparar dos copias.
 *
 * ## No es la exportacion legal
 *
 * RL-06 exige a la exportacion para la Inspeccion un formato **no propietario**,
 * y por eso aquella es CSV y no PDF. Este documento es de gestion: se imprime,
 * se firma a mano y se archiva en una carpeta de nomina. Un PDF ahi no estorba;
 * en un requerimiento, si.
 */
final readonly class PeriodReportPdf
{
    public function __construct(private ReportDocumentRenderer $renderer) {}

    public function respond(PeriodReport $report, ?string $issuer, string $digest): StreamedExport
    {
        $bytes = $this->renderer->renderPdf(
            $this->body($report, $issuer, $digest),
            $this->footer($report, $issuer, $digest),
        );

        return new StreamedExport(
            filename: PeriodReportLayout::filename($report, 'pdf'),
            contentType: 'application/pdf',
            digest: $digest,
            rowCount: $report->rowCount(),
            // El PDF no se puede emitir por partes —el motor lo compone entero
            // antes de devolverlo—, pero se sirve con la misma envoltura que los
            // otros dos para que las cabeceras no puedan divergir.
            body: static function () use ($bytes): void {
                echo $bytes;
            },
        );
    }

    private function body(PeriodReport $report, ?string $issuer, string $digest): string
    {
        return View::make('pdf.period-report', [
            'title' => PeriodReportLayout::text('document.title'),
            'metadata' => PeriodReportLayout::metadata($report, $issuer, $digest),
            'criteriaLabel' => PeriodReportLayout::text('document.criteria'),
            'criteria' => PeriodReportLayout::criteria($report),
            'header' => PeriodReportLayout::header(),
            'rows' => array_map(
                static fn (PeriodReportRow $row): array => PeriodReportLayout::cells($row),
                $report->rows,
            ),
            'emptyLabel' => PeriodReportLayout::text('document.empty'),
        ])->render();
    }

    /**
     * El fragmento que el motor repite en cada pagina.
     *
     * Va en una vista propia y no incrustado aqui porque Chromium **ignora las
     * hojas de estilo del documento** en el pie: todo el estilo tiene que ir en
     * linea, y eso es HTML, no PHP.
     */
    private function footer(PeriodReport $report, ?string $issuer, string $digest): string
    {
        return View::make('pdf.period-report-footer', [
            'generatedAt' => PeriodReportLayout::localInstant($report->generatedAt, $report->timeZone),
            'timeZone' => $report->timeZone,
            'issuer' => $issuer ?? PeriodReportLayout::text('document.issuer_unknown'),
            'issuerLabel' => PeriodReportLayout::text('document.issuer'),
            'period' => $report->range->isoFrom().' → '.$report->range->isoTo(),
            'periodLabel' => PeriodReportLayout::text('document.period'),
            'digestLabel' => PeriodReportLayout::text('document.digest'),
            'digest' => $digest,
        ])->render();
    }
}
