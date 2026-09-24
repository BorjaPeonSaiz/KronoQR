<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionOriginShare;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use Illuminate\Support\Facades\View;

/**
 * El cuadro de impacto como PDF **sellado** (**RF-IN-08**).
 *
 * ## Es el formato mas util de los tres, al contrario que en la nomina
 *
 * Alli el PDF esta descartado porque ningun programa de nomina importa un PDF.
 * Aqui es el que se adjunta a una renovacion de licencia y el que se lleva impreso
 * a una reunion, y por eso es el unico de los tres que lleva el sello repetido en
 * cada pagina.
 *
 * ## Que lleva el sello y por que cuatro cosas y no una
 *
 * El pie es **el mismo fragmento** que el del informe por periodo
 * (`pdf.period-report-footer`), y compartirlo es deliberado: el sello de dos
 * documentos del mismo producto no puede divergir. Dice cuando se genero —en la
 * zona del centro (ADR-040), no en UTC—, quien lo emitio —el nombre de la cuenta,
 * nunca su correo (regla dura 12) ni su `uuid`—, que periodo abarca y la huella
 * SHA-256 del contenido.
 *
 * El **cuerpo** si tiene vista propia (`pdf.adoption-report`), porque este
 * documento lleva dos tablas y aquella solo sabe pintar una.
 *
 * ## La huella es del contenido, no del binario
 *
 * Esta escrito en {@see AdoptionReportDigest} y aqui solo se imprime. La
 * consecuencia al leer un PDF: **dos PDF del mismo cuadro generados en dos
 * momentos distintos son dos ficheros distintos byte a byte y llevan la misma
 * huella**, porque el sello temporal no entra en ella. Y al reves: si una
 * correccion cambia las horas de marzo, la huella cambia aunque el papel se
 * parezca.
 *
 * ## El logotipo llega por un PUERTO
 *
 * `Reporting\Infrastructure\Export` no puede alcanzar `Shared\Infrastructure`
 * (Deptrac): ahi viven adaptadores que un escritor de ficheros no debe tocar. Asi
 * que quien lee el fichero es el adaptador de `Product` y aqui llega
 * {@see LogoImage}, ya leido y comprobado. Sin logotipo utilizable, `null`, y el
 * documento sale con el nombre solo — nadie se queda sin su cuadro por una imagen
 * que falta.
 */
final readonly class AdoptionReportPdfWriter
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
     * devolverlo—, y aqui eso no cuesta nada: son doce filas y cuatro origenes.
     */
    public function render(AdoptionReport $report, ?string $issuer, string $digest): string
    {
        return $this->renderer->renderPdf(
            $this->body($report, $issuer, $digest),
            $this->footer($report, $issuer, $digest),
        );
    }

    private function body(AdoptionReport $report, ?string $issuer, string $digest): string
    {
        $brand = $this->branding->current();
        $logo = $this->logos->current();

        return View::make('pdf.adoption-report', [
            'title' => AdoptionReportLayout::text('document.title'),
            // La marca de la instalacion, para la cabecera. El `title` de arriba es
            // el nombre del DOCUMENTO y no cambia: son dos cosas distintas.
            'brandName' => $brand->applicationName,
            'brandAccent' => $brand->accentColor,
            'brandLogo' => $logo instanceof LogoImage ? $logo->dataUri() : null,
            'metadata' => AdoptionReportLayout::metadata($report, $issuer, $digest),
            'criteriaLabel' => AdoptionReportLayout::text('document.criteria'),
            'criteria' => AdoptionReportLayout::criteria($report),
            'header' => AdoptionReportLayout::header(),
            'rows' => array_map(
                static fn (AdoptionIndicator $indicator): array => AdoptionReportLayout::cells($indicator),
                $report->indicators,
            ),
            'breakdownLabel' => AdoptionReportLayout::text('document.origin_breakdown'),
            'originHeader' => AdoptionReportLayout::originHeader(),
            'originRows' => array_map(
                static fn (AdoptionOriginShare $share): array => AdoptionReportLayout::originCells($share),
                $report->originBreakdown,
            ),
        ])->render();
    }

    /**
     * El fragmento que el motor repite en cada pagina, **compartido con el informe
     * por periodo**.
     *
     * Va en una vista y no incrustado aqui porque Chromium **ignora las hojas de
     * estilo del documento** en el pie: todo el estilo tiene que ir en linea, y eso
     * es HTML, no PHP.
     */
    private function footer(AdoptionReport $report, ?string $issuer, string $digest): string
    {
        return View::make('pdf.period-report-footer', [
            'generatedAt' => AdoptionReportLayout::localInstant($report->generatedAt, $report->timeZone),
            'timeZone' => $report->timeZone,
            'issuer' => $issuer ?? AdoptionReportLayout::text('document.issuer_unknown'),
            'issuerLabel' => AdoptionReportLayout::text('document.issuer'),
            'period' => $report->range->isoFrom().' → '.$report->range->isoTo(),
            'periodLabel' => AdoptionReportLayout::text('document.period'),
            'digestLabel' => AdoptionReportLayout::text('document.digest'),
            'digest' => $digest,
        ])->render();
    }
}
