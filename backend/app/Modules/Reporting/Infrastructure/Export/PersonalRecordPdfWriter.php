<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use DateTimeImmutable;
use Illuminate\Support\Facades\View;

/**
 * El registro propio como PDF **sellado** (`GET /api/v1/me/export?format=pdf`,
 * RF-ID-05, RL-05; PR19 de la verificacion de la 2.1.0).
 *
 * El Anexo B del doc 01 exige CSV **y PDF** para el historico personal: el CSV
 * cubre la portabilidad del RGPD y el PDF es lo que una persona presenta ante
 * un tercero. Hasta la 2.2.0 solo existia el CSV.
 *
 * ## Reutiliza las plantillas y el sello del informe por periodo
 *
 * Cuerpo `pdf.period-report` y pie `pdf.period-report-footer`, los mismos que
 * {@see PeriodReportPdfWriter} y que el cuadro de impacto: el sello de los
 * documentos de un mismo producto no puede divergir. Dice cuando se genero —en
 * la zona del centro, no en UTC—, quien lo descargo, que periodo abarca y la
 * huella SHA-256 del contenido ({@see PersonalRecordDigest}). El motor es el
 * mismo puerto {@see ReportDocumentRenderer}: sin Chromium, `503` con la salida
 * escrita dentro —pedirlo en CSV—, nunca un `500`.
 *
 * ## Quien lo «emite» es la propia persona
 *
 * En el informe de gestion el pie nombra a la cuenta que lo pidio. Aqui lo pide
 * la persona sobre sus propios datos, y el pie la nombra con el rotulo
 * «Descargado por». Su nombre va en el papel porque es su registro; no va en el
 * titulo —que acaba en los metadatos del PDF— ni en el nombre del fichero.
 *
 * ## La marca llega por puertos, como en los otros dos documentos
 *
 * Nombre, color y logotipo de la instalacion (regla dura 13). Sin logotipo, el
 * nombre solo: nadie se queda sin su registro por una imagen.
 */
final readonly class PersonalRecordPdfWriter
{
    public function __construct(
        private ReportDocumentRenderer $renderer,
        private BrandingProvider $branding,
        private BrandingLogoReader $logos,
    ) {}

    /**
     * Los bytes del documento compuesto.
     *
     * @param  string|null  $holderName  Nombre de la persona, o `null` si no se pudo resolver;
     *                                   el documento sale igual con el rotulo de persona no
     *                                   identificable.
     */
    public function render(
        WorkDayJournal $journal,
        ?string $holderName,
        string $digest,
        DateTimeImmutable $generatedAt,
    ): string {
        return $this->renderer->renderPdf(
            $this->body($journal, $holderName, $digest, $generatedAt),
            $this->footer($journal, $holderName, $digest, $generatedAt),
        );
    }

    private function body(
        WorkDayJournal $journal,
        ?string $holderName,
        string $digest,
        DateTimeImmutable $generatedAt,
    ): string {
        $brand = $this->branding->current();
        $logo = $this->logos->current();

        return View::make('pdf.period-report', [
            'title' => PersonalRecordLayout::text('title'),
            'brandName' => $brand->applicationName,
            // Oscurecido hasta 4,5:1 sobre el papel (MB3): es texto de 10 pt.
            'brandAccent' => $brand->accentForTextOnWhite(),
            'brandLogo' => $logo instanceof LogoImage ? $logo->dataUri() : null,
            'metadata' => PersonalRecordLayout::metadata($journal, $holderName, $digest, $generatedAt),
            'criteriaLabel' => PersonalRecordLayout::text('criteria_label'),
            'criteria' => PersonalRecordLayout::criteria(),
            'header' => PersonalRecordLayout::header(),
            'rows' => PersonalRecordLayout::rows($journal),
            'wrapColumns' => PersonalRecordLayout::wrappingColumnIndexes(),
            'emptyLabel' => PersonalRecordLayout::text('empty'),
        ])->render();
    }

    private function footer(
        WorkDayJournal $journal,
        ?string $holderName,
        string $digest,
        DateTimeImmutable $generatedAt,
    ): string {
        return View::make('pdf.period-report-footer', [
            'generatedAt' => PersonalRecordLayout::localInstant($generatedAt, $journal->timeZone),
            'timeZone' => $journal->timeZone,
            'issuer' => $holderName ?? PersonalRecordLayout::text('holder_unknown'),
            'issuerLabel' => PersonalRecordLayout::text('downloaded_by'),
            'period' => $journal->range->isoFrom().' → '.$journal->range->isoTo(),
            'periodLabel' => PersonalRecordLayout::text('period'),
            'digestLabel' => PersonalRecordLayout::text('digest'),
            'digest' => $digest,
        ])->render();
    }
}
