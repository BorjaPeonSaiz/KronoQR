<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Las clases de fichero que el producto escribe en el volumen `app-storage`
 * (ADR-045, condicion C10).
 *
 * ## Es el catalogo cerrado de la etiqueta `class` de las metricas
 *
 * Las cuatro series de los ficheros generados llevan **una sola etiqueta**, y
 * sus valores son exactamente estos casos. Nunca un `uuid`, un nombre ni una
 * ruta: el historico de metricas viaja en el paquete de diagnostico (regla dura
 * 21) y una etiqueta abierta seria una serie por exportacion.
 *
 * ## `data_export_work` cubre dos restos distintos
 *
 * El espacio de trabajo `.work-<uuid>/` y el temporal que `ZipArchive` deja
 * junto al ZIP mientras lo sella. Los dos son restos de una generacion
 * interrumpida, con todos los datos en claro, y se barren con la misma edad
 * minima: separarlos en dos valores de etiqueta no diria nada nuevo a quien mira
 * la alerta.
 *
 * ## Añadir una clase
 *
 * Un caso aqui, su raiz y su patron en el catalogo de infraestructura
 * (`GeneratedFileAreas`), y una llamada a la conciliacion compartida desde el
 * caso de uso que la purga. La prueba de inventario obliga a lo segundo.
 */
enum GeneratedFileClass: string
{
    /** El ZIP de la exportacion integra (RF-PD-14, RL-20). */
    case DataExport = 'data_export';

    /** Restos de generacion de la exportacion integra: `.work-<uuid>/` y temporales de `ZipArchive`. */
    case DataExportWork = 'data_export_work';

    /** El directorio `<uuid>/` de un informe en diferido (RF-IN-06). */
    case ReportExport = 'report_export';

    /** El temporal de la descarga HTTP de la exportacion legal (RF-IN-05). */
    case LegalExportTemporary = 'legal_export_tmp';

    /** La exportacion legal escrita por consola para la Inspeccion. Nunca se borra sola. */
    case LegalExportConsole = 'legal_export_console';

    /** El paquete de diagnostico (RF-PD-11, ADR-020). */
    case Diagnostics = 'diagnostics';
}
