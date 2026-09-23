<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Application\Port\ReportCriteriaNarrator;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Infrastructure\Export\PayrollCsvWriter;
use App\Modules\Reporting\Infrastructure\Export\PayrollXlsxWriter;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la salida a nomina como descarga sincrona (**RF-IN-07**).
 *
 * ## Es el MISMO escritor que usa la generacion en diferido
 *
 * No hay una version «para HTTP» y otra «para la cola»: los dos escritores
 * aceptan una ruta, y aqui la ruta es `php://output`. Dos implementaciones
 * acabarian entregando ficheros distintos segun por donde se pidieran, y el que
 * se creeria seria el equivocado — que es exactamente el argumento por el que la
 * exportacion del informe por periodo comparte consulta con la pantalla.
 *
 * ## Los criterios van en una **cabecera**, no dentro del fichero
 *
 * `X-Kronoqr-Export-Criteria` lleva las lineas de criterios ya traducidas al
 * idioma de la instalacion, en **base64 de UTF-8**: una cabecera HTTP no admite
 * ni acentos ni saltos de linea, y los criterios llevan los dos. Se decodifica y
 * se parte por `\n`.
 *
 * Y van fuera del fichero a proposito (decision 5 de la ficha 3.9): una fila de
 * comentario antes de la cabecera rompe la importacion del programa de nomina.
 * Esta es la diferencia deliberada con `/reports/period/export`, cuyo fichero
 * abre una persona y por eso los lleva dentro, en celdas visibles.
 *
 * ## `X-Kronoqr-Export-Rows` se calcula antes de transmitir
 *
 * En una `StreamedResponse` las cabeceras ya se han enviado cuando el escritor
 * termina, asi que el recuento sale de `rowCount()` del informe y no del valor
 * que devuelve el escritor. Son el mismo numero —el escritor cuenta una fila por
 * fila del informe— y el escritor sigue devolviendolo porque la generacion en
 * diferido si lo necesita despues, para `report_exports.row_count`.
 *
 * ## `no-store, private` no es opcional
 *
 * El cuerpo lleva horas de personas identificadas, igual que el informe por
 * periodo. Mismo criterio que la exportacion legal y que el historico del portal.
 */
final readonly class PayrollExportDocument
{
    public function __construct(
        private PayrollCsvWriter $csv,
        private PayrollXlsxWriter $xlsx,
        /**
         * Las mismas lineas de criterios que guarda la exportacion en diferido y
         * que escribe dentro del suyo el informe por periodo. Por el puerto y no
         * por el escritor del informe: si esta clase compusiera las suyas, el
         * fichero diria una cosa y la pantalla otra sobre el mismo informe.
         */
        private ReportCriteriaNarrator $criteria,
    ) {}

    public function respond(PeriodReport $report, PayrollLayout $layout, ReportDelivery $format): StreamedResponse
    {
        $extension = self::extensionOf($format);

        return new StreamedResponse(
            function () use ($report, $layout, $format): void {
                match ($format) {
                    ReportDelivery::Csv => $this->csv->write($report, $layout, 'php://output'),
                    ReportDelivery::Xlsx => $this->xlsx->write($report, $layout, 'php://output'),
                    // Inalcanzable: el `FormRequest` solo admite los dos de
                    // arriba. Se declara con excepcion y no con un caso por
                    // omision para que añadir un formato rompa aqui, donde se ve,
                    // en lugar de producir un CSV en silencio.
                    default => throw new LogicException('La salida a nomina solo se sirve en CSV o XLSX.'),
                };
            },
            200,
            [
                'Content-Type' => $format === ReportDelivery::Csv
                    ? PayrollCsvWriter::contentTypeFor($layout->encoding)
                    : PayrollXlsxWriter::CONTENT_TYPE,
                // Sin nombre de persona y sin identificador: solo el periodo
                // (regla dura 21). Un adjunto llamado «nomina-Lucia.csv» divulga
                // a quien se esta mirando con solo ver la bandeja de entrada.
                'Content-Disposition' => 'attachment; filename='.self::filename($report, $extension),
                'Cache-Control' => 'no-store, private',
                'X-Kronoqr-Export-Criteria' => base64_encode(
                    implode("\n", $this->criteria->linesFor($report)),
                ),
                'X-Kronoqr-Export-Rows' => (string) $report->rowCount(),
            ],
        );
    }

    /**
     * El nombre del fichero: producto, concepto y periodo. Nada mas.
     *
     * `nomina` y no `horas` para que no se confunda en una carpeta de descargas
     * con el informe por periodo, que lleva las mismas fechas y otro contenido.
     */
    private static function filename(PeriodReport $report, string $extension): string
    {
        return 'kronoqr-nomina-'.$report->range->isoFrom().'_'.$report->range->isoTo().'.'.$extension;
    }

    private static function extensionOf(ReportDelivery $format): string
    {
        return $format === ReportDelivery::Xlsx ? 'xlsx' : 'csv';
    }
}
