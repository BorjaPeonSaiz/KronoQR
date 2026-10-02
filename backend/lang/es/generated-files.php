<?php

declare(strict_types=1);

/*
 * Lo que dicen por consola las purgas de los ficheros generados (ADR-045):
 * `product:export-all --purge`, `reporting:purge-expired-exports` y
 * `compliance:purge-legal-export-temp`.
 *
 * Lo lee quien administra el servidor, en el idioma de la instalacion. Solo
 * cifras: ni nombres de fichero, ni rutas, ni `uuid` (regla dura 21), porque la
 * salida de una tarea programada acaba en el log tecnico.
 */

return [

    'data_exports' => [
        'nothing_expired' => 'No hay ninguna exportacion integra caducada que purgar.',
        'purged' => 'Purgadas :count exportaciones integras caducadas. Las filas se conservan con su estado.',
        'released' => 'Liberadas :count exportaciones que se quedaron a medias (motivo «stale»): ya se puede pedir una nueva.',
        'orphans' => 'Borrados :count restos sin exportacion viva (un ZIP sin fila, o el directorio de trabajo de una '
            .'generacion interrumpida).',
        'missing' => 'ATENCION: :count exportaciones han perdido su fichero antes de caducar. Queda asiento '
            .'«data_export.file_missing» en la auditoria. Si no acabas de restaurar una copia, averigua quien lo '
            .'borro o se lo llevo.',
        'diagnostics' => 'Borrados :count paquetes de diagnostico que superaban su plazo (PRODUCT_DIAGNOSTICS_RETENTION_DAYS).',
    ],

    'report_exports' => [
        'summary' => 'Informes en diferido purgados: :purged. Trabajos atascados liberados: :released. Ficheros '
            .'huerfanos borrados: :orphans.',
        'missing' => 'ATENCION: :count informes han perdido su fichero antes de caducar. Queda asiento '
            .'«report_export.file_missing» en la auditoria. Si no acabas de restaurar una copia, averigua quien lo '
            .'borro o se lo llevo.',
    ],

    'legal_exports' => [
        'temporaries' => 'Temporales huerfanos borrados: :count (ventana: :hours h).',
        'console_overdue' => 'Hay :count exportaciones legales de consola con mas de :days dias en el servidor. No se '
            .'borran solas: borralas en cuanto las hayas entregado.',
    ],

];
