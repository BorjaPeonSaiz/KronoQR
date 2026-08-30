<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Exception\ReportRenderingUnavailable;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * Un motor de PDF de mentira para las pruebas de borde (RF-IN-04, tarea 2.9).
 *
 * ## Por que existe
 *
 * Componer un PDF de verdad arranca un Chromium: unos segundos por caso, un
 * proceso externo y una dependencia del contenedor. Las pruebas de **feature**
 * comprueban el borde —tipo de contenido, nombre de fichero, huella, `503`
 * cuando el motor falla— y para eso el motor no hace falta.
 *
 * El PDF de verdad, con su sello y su huella dentro, lo compone y lo lee
 * `tests/Integration/Reporting/PeriodReportPdfSealTest.php`. Es el mismo reparto
 * que hace `FakeCardRenderer` con el PDF de las tarjetas.
 *
 * ## Guarda el HTML que se le entrego
 *
 * Es lo que convierte la prueba del PDF en algo mas que «responde 200 con
 * `application/pdf`»: se puede afirmar que el documento lleva las horas, los
 * criterios y ninguna referencia a la red (ADR-016).
 */
final class FakeReportDocumentRenderer implements ReportDocumentRenderer
{
    /** Bytes de un PDF minimo. No es un PDF valido y no tiene que serlo. */
    public const string BYTES = "%PDF-1.7\nkronoqr-fake\n%%EOF\n";

    private static ?string $html = null;

    private static ?string $footer = null;

    public function __construct(private readonly bool $fails = false) {}

    /** Sustituye el motor real por este en el contenedor. */
    public static function bind(): void
    {
        self::$html = null;
        self::$footer = null;

        App::instance(ReportDocumentRenderer::class, new self);
    }

    /** Un motor que no arranca: lo que pasa en un servidor sin Chromium. */
    public static function bindFailing(): void
    {
        App::instance(ReportDocumentRenderer::class, new self(fails: true));
    }

    public static function lastHtml(): string
    {
        return self::$html ?? throw new RuntimeException('No se ha compuesto ningun PDF.');
    }

    public static function lastFooter(): string
    {
        return self::$footer ?? throw new RuntimeException('No se ha compuesto ningun PDF.');
    }

    public function renderPdf(string $html, string $footerHtml): string
    {
        self::$html = $html;
        self::$footer = $footerHtml;

        if ($this->fails) {
            throw ReportRenderingUnavailable::engineFailed(
                new RuntimeException('Chromium no esta instalado en este contenedor.'),
            );
        }

        return self::BYTES;
    }
}
