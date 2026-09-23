<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use Illuminate\Support\Facades\View;

/**
 * El informe por periodo como PDF **sellado** (**RF-IN-04**, RF-IN-06).
 *
 * ## Una sola implementacion para los dos caminos
 *
 * Compone los bytes una vez y quien llama decide que hacer con ellos: la
 * respuesta sincrona los emite, el trabajo en cola los escribe en
 * `REPORTING_EXPORT_PATH`. Ver el docblock de {@see PeriodReportCsvWriter}.
 *
 * ## Que lleva el sello y por que cuatro cosas y no una
 *
 * El pie se repite en **todas** las paginas —lo compone el motor, no el cuerpo
 * del documento— y dice:
 *
 *   1. **Cuando se genero**, en la zona del centro (ADR-040) y diciendo cual es.
 *      En UTC parecería generado por otro sistema.
 *   2. **Quien lo emitio**, por el nombre de la cuenta. Nunca su correo (regla
 *      dura 12) y nunca su `uuid`, que no le dice nada a quien lee un papel.
 *   3. **Que periodo abarca**, porque una hoja suelta fotocopiada del monton
 *      tiene que seguir diciendo de que mes habla.
 *   4. **La huella SHA-256 del contenido**, que es lo que convierte «este es el
 *      informe de marzo» en una afirmacion comprobable.
 *
 * ## La huella es del contenido, no del binario
 *
 * Esta escrito en {@see PeriodReportDigest} y aqui solo se imprime. La
 * consecuencia que hay que tener presente al leer un PDF: **dos PDF del mismo
 * informe generados en dos momentos distintos son dos ficheros distintos byte a
 * byte y llevan la misma huella**, porque el sello temporal no entra en ella. Y
 * al reves: si una correccion cambia una hora, la huella cambia aunque el papel
 * se parezca.
 *
 * **Ojo con la otra huella**: la que guarda `report_exports.sha256` es la del
 * FICHERO, calculada sobre los bytes ya escritos, y sirve para comprobar que la
 * descarga llego entera. Son dos cifras que responden dos preguntas distintas y
 * por eso conviven.
 *
 * ## No es la exportacion legal
 *
 * RL-06 exige a la exportacion para la Inspeccion un formato **no propietario**,
 * y por eso aquella es CSV y no PDF. Este documento es de gestion: se imprime, se
 * firma a mano y se archiva en una carpeta de nomina.
 *
 * ## Y no hay PDF de nomina
 *
 * `ReportExportKind::allows()` lo deja fuera y el `CHECK` de la migracion lo
 * garantiza: un programa de nomina no importa un PDF.
 *
 * ## El logotipo llega por un PUERTO
 *
 * `Reporting\Infrastructure\Export` **no puede alcanzar `Shared\Infrastructure`**
 * (Deptrac): ahi viven adaptadores que un escritor de ficheros no debe tocar. Asi
 * que quien lee el fichero es el adaptador de `Product` y aqui llega
 * {@see LogoImage}, ya leido y ya comprobado. Sin logotipo utilizable, `null`, y
 * el informe sale con el nombre solo — nadie se queda sin su informe por una
 * imagen que falta.
 */
final readonly class PeriodReportPdfWriter
{
    public function __construct(
        private ReportDocumentRenderer $renderer,
        private BrandingProvider $branding,
        private BrandingLogoReader $logos,
    ) {}

    /**
     * Los bytes del documento compuesto.
     *
     * El PDF no se puede emitir por partes —el motor lo compone entero antes de
     * devolverlo—, y por eso este es el unico de los tres formatos que no
     * transmite. La consecuencia esta asumida y escrita en la prueba de volumen:
     * un informe de quince mil filas no se imprime, se abre en una hoja de
     * calculo.
     */
    public function render(PeriodReport $report, ?string $issuer, string $digest): string
    {
        return $this->renderer->renderPdf(
            $this->body($report, $issuer, $digest),
            $this->footer($report, $issuer, $digest),
        );
    }

    /**
     * Escribe el PDF en una ruta del disco y devuelve las filas de datos.
     *
     * @throws ReportExportWriteFailed si el fichero no se puede escribir
     */
    public function write(PeriodReport $report, ?string $issuer, string $digest, string $path): int
    {
        $bytes = $this->render($report, $issuer, $digest);

        if (@file_put_contents($path, $bytes) === false) {
            // Sin la ruta en el mensaje (regla dura 21): el log tecnico viaja al
            // fabricante dentro del paquete de diagnostico.
            throw ReportExportWriteFailed::of('no se pudo escribir el fichero PDF');
        }

        return $report->rowCount();
    }

    private function body(PeriodReport $report, ?string $issuer, string $digest): string
    {
        $brand = $this->branding->current();
        $logo = $this->logos->current();

        return View::make('pdf.period-report', [
            'title' => PeriodReportLayout::text('document.title'),
            // La marca de la instalacion, para la cabecera. El `title` de arriba
            // es el nombre del DOCUMENTO y no cambia: son dos cosas distintas.
            'brandName' => $brand->applicationName,
            'brandAccent' => $brand->accentColor,
            'brandLogo' => $logo instanceof LogoImage ? $logo->dataUri() : null,
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
