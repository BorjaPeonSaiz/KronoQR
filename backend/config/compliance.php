<?php

declare(strict_types=1);

/*
 * Retencion de los ficheros que produce la exportacion legal (RF-IN-05,
 * doc 02 Anexo C).
 *
 * Dos rutas y solo una se limpia sola:
 *
 *   - storage/app/legal-exports/ es la copia DELIBERADA que escribe
 *     `compliance:legal-export` (via consola) para entregar a la Inspeccion.
 *     Su custodia y su borrado son responsabilidad de quien la genero, no de
 *     un cron: ver docs/runbooks/requerimiento-inspeccion.md §6.
 *   - storage/framework/legal-exports/ es el temporal que
 *     `LegalExportController` (via HTTP) crea para servir la descarga y borra
 *     con `deleteFileAfterSend()` al terminar. Si el cliente aborta la
 *     descarga a medias, ese borrado nunca corre y el fichero -con datos
 *     personales de la plantilla- queda huerfano en disco.
 *
 * Este fichero solo gobierna la segunda ruta.
 */

return [

    /*
     * Horas que un temporal huerfano de storage/framework/legal-exports/
     * puede vivir antes de que `compliance:purge-legal-export-temp` lo borre.
     * Generoso a proposito: una descarga en curso sobre una red mala y un
     * periodo largo no debe competir con la ventana y acabar borrada a mitad
     * de streaming.
     */
    'legal_export_temp_retention_hours' => (int) env('COMPLIANCE_LEGAL_EXPORT_TEMP_RETENTION_HOURS', 6),

];
