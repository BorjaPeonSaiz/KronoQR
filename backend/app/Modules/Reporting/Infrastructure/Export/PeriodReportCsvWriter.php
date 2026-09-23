<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Support\Facades\App;

/**
 * El informe por periodo como CSV, escrito **a un descriptor cualquiera**
 * (**RF-IN-04**, RF-IN-06).
 *
 * ## Una sola implementacion para los dos caminos
 *
 * Lo usan `GET /reports/period/export` —que abre `php://output` y transmite— y
 * el trabajo en cola de RF-IN-06 —que abre un fichero de
 * `REPORTING_EXPORT_PATH`—. Con dos implementaciones, el fichero que llega por
 * el enlace de descarga y el que se descarga desde la pantalla podrian
 * discrepar, y el que se creeria seria el equivocado. La diferencia entre los dos
 * caminos es **el destino**, no el contenido, y por eso lo unico que cambia es el
 * descriptor.
 *
 * Vive en `Infrastructure/Export` y no en `Http/Response` por eso mismo: el
 * trabajo en cola no puede alcanzar la capa Http (Deptrac), y un escritor de
 * ficheros no es una respuesta HTTP — es la **forma de un fichero**, igual que
 * `CsvDialect` para el dialecto compartido.
 *
 * ## Por que no lo escribe `spatie/simple-excel`
 *
 * El plan de la tarea 2.9 nombra esa libreria para los tres formatos, y para el
 * XLSX es la que se usa. Para el CSV **no se puede**, y el motivo esta escrito
 * desde la tarea 1.17 en el escritor de la exportacion legal —nombrado en prosa
 * porque `Reporting` no puede importar `Compliance` (doc 02 §1.6)—: su escritor
 * de CSV llama a `fputcsv` con el fin de linea por omision, `\n`, y no expone
 * ninguna opcion para cambiarlo. Con el es imposible entregar el `\r\n` del RFC
 * 4180 que espera Excel en Windows.
 *
 * Escribirlo con la libreria habria dado un tercer CSV del producto con un
 * formato distinto de los otros dos. Eso ya paso una vez —el historico del portal
 * salia con `\r\n` y el de la Inspeccion con `\n`, y nadie se entero— y es
 * exactamente lo que {@see CsvDialect} existe para impedir. Lo que justificaba
 * elegir la libreria, «no carga en memoria un mes de 500 empleados», se conserva
 * intacto: aqui se escribe fila a fila sobre el descriptor.
 *
 * ## El separador depende del idioma de la instalacion
 *
 * `;` en español —donde la coma es el separador decimal y Excel espera punto y
 * coma— y `,` en ingles, donde `;` metaria todas las columnas en la primera
 * celda. Lo decide {@see CsvDialect::delimiterFor()} con `app.locale`, que es
 * configuracion de la instalacion (ADR-017, regla dura 13). El BOM va siempre:
 * sin el, Excel con configuracion regional española lee el fichero en
 * Windows-1252 y «Duración» sale «DuraciÃ³n».
 *
 * **En diferido el idioma lo fija el trabajo** antes de llamar aqui, con el mismo
 * criterio que el middleware `locale.installation` en la descarga sincrona: un
 * documento se entrega a un tercero y lo abre un programa cuyo idioma no es el
 * del navegador que lo pidio.
 *
 * ## Los criterios van en un bloque **visible**, antes de la tabla
 *
 * No en un comentario, no en las propiedades del fichero: en celdas, seguidos de
 * una linea en blanco. La linea en blanco es lo que permite que una hoja de
 * calculo reconozca la tabla al seleccionarla, y el bloque es lo que hace que el
 * fichero se explique solo cuando alguien lo abra dos años despues. Es la misma
 * disposicion que los otros dos CSV del producto.
 *
 * **La salida a nomina no lleva este bloque** y por eso tiene su propio escritor
 * (RF-IN-07): una fila de comentario al principio rompe la importacion del
 * programa de nomina.
 */
final readonly class PeriodReportCsvWriter
{
    /**
     * Escribe el CSV en una ruta del disco y devuelve las filas de datos.
     *
     * @throws ReportExportWriteFailed si el fichero no se puede abrir
     */
    public function write(PeriodReport $report, ?string $issuer, string $digest, string $path): int
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            // Sin la ruta en el mensaje: el log tecnico viaja al fabricante dentro
            // del paquete de diagnostico y una ruta del servidor no aporta nada
            // que el nombre del fichero no diga (regla dura 21).
            throw ReportExportWriteFailed::of('no se pudo abrir el fichero CSV');
        }

        try {
            return $this->writeTo($handle, $report, $issuer, $digest);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Escribe el CSV en un descriptor ya abierto y devuelve las filas de datos.
     *
     * **No lo cierra**: quien lo abrio decide cuando. En la respuesta sincrona
     * ese descriptor es `php://output` y cerrarlo a destiempo cortaria la
     * transmision.
     *
     * @param  resource  $handle
     */
    public function writeTo($handle, PeriodReport $report, ?string $issuer, string $digest): int
    {
        $delimiter = CsvDialect::delimiterFor(App::getLocale());

        // La marca de orden de bytes va antes que nada: es lo que le dice a la
        // hoja de calculo que esto es UTF-8.
        CsvDialect::writeByteOrderMark($handle);

        self::writeHeader($handle, $report, $issuer, $digest, $delimiter);

        $rows = 0;

        foreach ($report->rows as $row) {
            CsvDialect::writeRow($handle, PeriodReportLayout::cells($row), $delimiter);
            $rows++;
        }

        return $rows;
    }

    /**
     * Metadatos, criterios, linea en blanco y rotulos.
     *
     * @param  resource  $handle
     */
    private static function writeHeader($handle, PeriodReport $report, ?string $issuer, string $digest, string $delimiter): void
    {
        CsvDialect::writeRow($handle, [PeriodReportLayout::text('document.title')], $delimiter);

        foreach (PeriodReportLayout::metadata($report, $issuer, $digest) as [$label, $value]) {
            CsvDialect::writeRow($handle, [$label, $value], $delimiter);
        }

        foreach (PeriodReportLayout::criteria($report) as $index => $criterion) {
            CsvDialect::writeRow($handle, [
                $index === 0 ? PeriodReportLayout::text('document.criteria') : '',
                $criterion,
            ], $delimiter);
        }

        CsvDialect::writeRow($handle, [], $delimiter);
        CsvDialect::writeRow($handle, PeriodReportLayout::header(), $delimiter);
    }
}
