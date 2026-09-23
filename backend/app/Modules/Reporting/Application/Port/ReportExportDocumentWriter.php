<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;

/**
 * Escribe el fichero del informe en diferido **en una ruta del disco**
 * (**RF-IN-06**, **RF-IN-07**, decision 4 de la ficha 3.9).
 *
 * ## Es el mismo escritor que la descarga sincrona, y eso es el requisito
 *
 * Los adaptadores de este puerto viven en `Reporting\Infrastructure\Export` y
 * son exactamente los que usa `GET /reports/period/export` para transmitir la
 * respuesta: una sola implementacion por formato. Con dos, el fichero que
 * alguien recibe por el enlace de descarga y el que descarga desde la pantalla
 * podrian discrepar, y el que se creeria seria el equivocado — que es el mismo
 * argumento por el que la exportacion sincrona reutiliza la consulta del panel.
 *
 * La diferencia entre los dos caminos es **el destino**, no el contenido: alli
 * `php://output` y aqui un fichero de `REPORTING_EXPORT_PATH`.
 *
 * ## Por que un puerto, si el escritor es codigo propio
 *
 * Porque `Application` no alcanza `Infrastructure` (Deptrac, doc 02 §1.6) y
 * porque el caso de uso **no debe saber en que formato se escribe**: decide que
 * hay que escribir, no como se abre un `SimpleExcelWriter` ni como se lanza
 * Chromium. Y porque asi la prueba de la generacion puede sustituirlo por un
 * doble sin arrancar un navegador.
 *
 * ## Devuelve las filas escritas
 *
 * No el tamaño ni la huella: esas las mide el almacenamiento sobre el fichero ya
 * cerrado, que es la unica forma de que digan la verdad. Lo que el escritor sabe
 * y nadie mas es cuantas filas de datos llegaron al fichero — el numero que
 * permite comprobar que la descarga esta completa sin abrirla.
 */
interface ReportExportDocumentWriter
{
    /**
     * El nombre con el que se entrega el fichero.
     *
     * **Sin ningun dato personal** (regla dura 21): el periodo y la extension, y
     * para la nomina el rotulo que la distingue. Ni el nombre de quien lo pidio
     * ni el de nadie que salga dentro — ese nombre viaja en `Content-Disposition`
     * y acaba en el historial de descargas de un navegador compartido.
     */
    public function fileNameFor(ReportExport $export, PeriodReport $report): string;

    /**
     * Escribe el fichero y devuelve cuantas filas de datos lleva.
     *
     * @param  string  $path  Ruta absoluta, con el directorio ya creado.
     */
    public function write(ReportExport $export, PeriodReport $report, string $path): int;
}
