<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Http\Support\PeriodReportDigest;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Elige el escritor segun el formato pedido y sella el documento
 * (**RF-IN-04**).
 *
 * ## Por que la eleccion no esta en el controlador
 *
 * Porque no es una sola linea: son tres decisiones que los tres formatos
 * comparten y que tienen que tomarse **igual** en los tres, o el fichero deja de
 * ser el mismo informe segun como se descargue.
 *
 *   1. **La huella se calcula una vez**, sobre el informe, antes de saber en que
 *      formato va a salir. Es lo que hace que el CSV y el PDF de marzo lleven la
 *      misma. Calcularla dentro de cada escritor invitaria a que uno de los tres
 *      la calculara sobre otra cosa.
 *   2. **El emisor se resuelve una vez**, por el puerto, y con el `uuid` que ya
 *      trae la peticion autenticada.
 *   3. **Las cabeceras son las de {@see StreamedExport}**, iguales para los tres.
 *
 * Al controlador le queda invocar la consulta y devolver lo que salga de aqui.
 *
 * ## `ReportDelivery::Json` no llega
 *
 * Ese caso no es un fichero: lo sirve `PeriodReportResource` desde el otro
 * endpoint. El `match` es exhaustivo sobre el enumerado y lo declara con una
 * excepcion en lugar de con un caso por omision, para que añadir un formato
 * nuevo rompa aqui —donde se ve— y no produzca un CSV en silencio.
 */
final readonly class PeriodReportDocument
{
    public function __construct(
        private PeriodReportPdf $pdf,
        private ReportIssuerDirectory $issuers,
    ) {}

    public function respond(PeriodReport $report, ReportDelivery $format, string $actorUuid): StreamedResponse
    {
        $digest = PeriodReportDigest::of($report)->toText();
        $issuer = $this->issuers->displayNameOf($actorUuid);

        $export = match ($format) {
            ReportDelivery::Csv => PeriodReportCsv::respond($report, $issuer, $digest),
            ReportDelivery::Xlsx => PeriodReportXlsx::respond($report, $issuer, $digest),
            ReportDelivery::Pdf => $this->pdf->respond($report, $issuer, $digest),
            ReportDelivery::Json => throw new LogicException(
                'El informe en JSON lo sirve GET /api/v1/reports/period, no la descarga.',
            ),
        };

        return $export->toResponse();
    }
}
