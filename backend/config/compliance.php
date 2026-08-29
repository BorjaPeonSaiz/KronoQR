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

    /*
     * Ventana, en segundos, en la que las denegaciones por alcance del MISMO actor
     * sobre el MISMO conjunto de datos se agrupan en un solo asiento de
     * `audit_log` (RF-ID-03, RS-05, ADR-010, ADR-037).
     *
     * POR QUE HAY VENTANA. `access.denied` es la unica escritura de `audit_log`
     * que provoca quien esta siendo rechazado, no quien gestiona: un bucle de
     * peticiones denegadas es un bucle de escrituras bajo el candado global de
     * ADR-010, el mismo por el que pasa cada fichaje. ADR-037 nombra esta palanca
     * —agrupar por frecuencia detras del puerto— para exactamente este problema.
     *
     * EL ASIENTO NO SE PIERDE: la primera denegacion de cada ventana se escribe
     * siempre —es lo que exige el escenario «Aislamiento por departamento» del doc
     * 01 §11— y las repeticiones se cuentan en
     * `repeated_since_last_entry` del asiento siguiente.
     *
     * UN MINUTO NO ES UNA MEDICION: es el grano con el que se lee un incidente sin
     * perder resolucion. `0` desactiva la agrupacion y devuelve un asiento por
     * denegacion, para un cliente que prefiera la fila a la contencion.
     */
    'authorization_denial_window_seconds' => (int) env('COMPLIANCE_AUTHZ_DENIAL_WINDOW_SECONDS', 60),

];
