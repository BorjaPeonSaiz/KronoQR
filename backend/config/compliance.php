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

    /*
     * Divulgaciones de datos personales que se AGRUPAN por ventana en lugar de
     * dejar un asiento por lectura (RS-05, RL-15, ADR-037).
     *
     * LA LISTA ES CERRADA Y CORTA A PROPOSITO. Lo normal es que cada divulgacion
     * deje su asiento: quien lista la plantilla o descarga el padron lo hace una
     * vez y hay que poder decir despues que se llevo. Aqui solo entran los
     * conjuntos que un cliente **sondea**, donde un asiento por peticion no
     * responde mejor a RL-15 —dice lo mismo veinte mil veces— y ademas mete miles
     * de escrituras diarias bajo el candado global de ADR-010, el mismo por el
     * que pasa cada fichaje.
     *
     * Hoy hay uno: `live_presence`, la vista del panel que se pide cada 15 s
     * cuando el WebSocket no llega (RNF-D-03, ADR-011). Añadir un conjunto aqui
     * es una decision sobre el valor probatorio del trail, no un ajuste de
     * rendimiento.
     *
     * EL HECHO NO SE PIERDE: el primer asiento de cada ventana se escribe siempre
     * y las repeticiones se cuentan en `repeated_since_last_entry` del siguiente.
     *
     * QUINCE MINUTOS porque la pregunta que RL-15 hace es «¿tuvo esa cuenta la
     * presencia de la plantilla delante?», y esa se responde igual de bien con un
     * apunte cada cuarto de hora que con cuatro por minuto. `0` desactiva la
     * agrupacion y devuelve un asiento por lectura.
     */
    /*
     * Deteccion automatica de incidencias (RF-PR-01, tarea 2.6).
     *
     * LA VENTANA ES LA DECISION DE RETROACTIVIDAD, y esta escrita en el doc 01 §4
     * junto a RN-08: la revision diaria NO reprocesa el historico. Recalcular el
     * pasado abriria incidencias sobre jornadas ya entregadas a la plantilla o a
     * la Inspeccion, y una incidencia abierta hoy sobre una jornada de hace dos
     * anos no describe nada que nadie pueda corregir.
     *
     * SIETE DIAS porque es lo que cubre la semana de una nomina y el tiempo real
     * en el que una correccion sigue siendo util: mas atras, lo que hay que hacer
     * no es abrir una incidencia sino corregir con motivo (RF-PA-04). Ampliarla
     * para una ejecucion concreta es `--days`, una decision consciente de quien
     * lanza el comando.
     *
     * LOS TRAMOS TODAVIA ABIERTOS NO ENTRAN EN ESTA VENTANA y se revisan siempre,
     * sea cual sea su fecha: un turno sin cerrar no es historia, es un hecho que
     * sigue creciendo, y es el que ve la alerta «Turnos abiertos > 12 h» del
     * doc 01 §9.3.
     *
     * NO ES UN UMBRAL LEGAL NI OPERATIVO: no dice cuando algo es anomalo —eso lo
     * dicen `compliance_profiles` e `installation_settings` (regla dura 14)— sino
     * hasta donde mira el proceso. Por eso vive aqui y no en una tabla.
     */
    'incident_detection' => [
        'lookback_days' => (int) env('COMPLIANCE_INCIDENT_LOOKBACK_DAYS', 7),
    ],

    'disclosure_grouping' => [
        'datasets' => ['live_presence'],
        'window_seconds' => (int) env('COMPLIANCE_DISCLOSURE_WINDOW_SECONDS', 900),
    ],

];
