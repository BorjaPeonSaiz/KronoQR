<?php

declare(strict_types=1);

/*
 * Retencion de los ficheros que produce la exportacion legal (RF-IN-05,
 * doc 02 Anexo C, ADR-045).
 *
 * Dos rutas, las dos dentro del volumen `app-storage` (ADR-045), y solo una se
 * limpia sola:
 *
 *   - storage/app/legal-exports/ es la copia DELIBERADA que escribe
 *     `compliance:legal-export` (via consola) para entregar a la Inspeccion.
 *     Su custodia y su borrado son responsabilidad de quien la genero, no de
 *     un cron: ver docs/runbooks/requerimiento-inspeccion.md §6. Como ya no
 *     desaparece al recrear el contenedor, `product:doctor` y una metrica con
 *     alerta avisan cuando lleva mas de 30 dias en el servidor.
 *   - storage/app/tmp/legal-exports/ es el temporal que
 *     `LegalExportController` (via HTTP) crea para servir la descarga y borra
 *     con `deleteFileAfterSend()` al terminar. Si el cliente aborta la
 *     descarga a medias, ese borrado nunca corre y el fichero -con datos
 *     personales de la plantilla- queda huerfano en disco. Vivia en
 *     storage/framework, en la capa de cada contenedor: lo escribia `app` y lo
 *     purgaba `scheduler`, que no lo veia.
 */

return [

    /*
     * Las dos raices, FIJAS y sin variable de entorno a proposito: tienen que
     * estar dentro del volumen compartido para que la purga del `scheduler` vea
     * lo que escribe `app`, y una ruta configurable que saliera de el
     * reproduciria R3-PL-01. Son raices de clase de ADR-045: `product:doctor`
     * comprueba que no se solapan con ninguna otra.
     */
    'legal_export_temp_path' => storage_path('app/tmp/legal-exports'),
    'legal_export_console_path' => storage_path('app/legal-exports'),

    /*
     * Dias a partir de los cuales una exportacion legal de consola que sigue en
     * el servidor merece un aviso (ADR-045 §f). NO se borra: es custodia humana.
     * Lo que ocurre a los 30 dias es que `product:doctor` avisa y la serie
     * `generated_files_overdue{class="legal_export_console"}` deja de ser cero.
     */
    'legal_export_console_warning_days' => 30,

    /*
     * Horas que un temporal huerfano de storage/app/tmp/legal-exports/
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
    'disclosure_grouping' => [
        'datasets' => ['live_presence'],
        'window_seconds' => (int) env('COMPLIANCE_DISCLOSURE_WINDOW_SECONDS', 900),
    ],

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

    /*
     * Deteccion de patrones anomalos de uso de credencial (RF-PR-06, RN-16,
     * tarea 3.11).
     *
     * TREINTA DIAS Y NO SIETE, al contrario que su hermana de arriba. Lo que
     * esta pasada busca es lo «sistematico» (RF-PR-06, doc 05 §12): que dos
     * personas coincidan en el mismo quiosco separadas por segundos VARIOS DIAS.
     * Sobre una semana eso no se puede afirmar -el Gherkin del doc 01 §11 ya
     * habla de cinco dias de repeticion-, y una ventana corta convertiria la
     * regla en un detector de companeros que llegan juntos el mismo lunes.
     *
     * NO ABRE INCIDENCIAS SOBRE EL PASADO por mirar mas atras: lo que mira son
     * escaneos, no jornadas, y el hallazgo se fecha en el dia en que el par
     * alcanzo el umbral. La incidencia describe un habito en curso, no una
     * jornada ya entregada.
     *
     * NO ES UN UMBRAL: no dice cuando algo es anomalo -eso lo dicen los tres
     * ajustes de `installation_settings`: ventana, repeticiones y transito
     * minimo (regla dura 14)- sino hasta donde mira el proceso. Por eso vive
     * aqui y no en una tabla, igual que `incident_detection`.
     */
    'pattern_detection' => [
        'lookback_days' => (int) env('COMPLIANCE_PATTERN_LOOKBACK_DAYS', 30),
    ],

    /*
     * Conciliacion entre el registro horario y su auditoria (ADR-057 §4, RL-04,
     * RS-07): `compliance:reconcile-work-record`.
     *
     * LA VENTANA DE LA PASADA DIARIA, en dias. Cada noche se cruzan los tramos
     * con `work_date` de los ultimos N dias —y todo lo que la auditoria apunto en
     * ese plazo, incluidas las correcciones de hoy sobre tramos antiguos— con su
     * ultimo asiento de `audit_log`. Es lo que hace que una edicion directa de un
     * fichaje reciente salga al dia siguiente.
     *
     * SIETE DIAS, por lo mismo que la deteccion de incidencias: es la semana de
     * una nomina y cubre de sobra un fin de semana en el que nadie mira las
     * alertas. Lo antiguo no queda sin mirar: el domingo corre la pasada completa
     * (`--full`) sobre todo el registro, que es la que ve un borrado o una edicion
     * de un tramo de hace meses. Medida, la completa tarda 1 min 42 s con cuatro
     * años de 300 personas; la diaria, 2,5 s.
     *
     * NO ES UN UMBRAL LEGAL NI DE UN CLIENTE: dice hasta donde mira el proceso, no
     * cuando algo esta mal (regla dura 13). Una pasada mas ancha para una
     * ejecucion concreta es `--days`, una decision consciente de quien la lanza.
     */
    'work_record_reconciliation' => [
        'window_days' => 7,
    ],

    /*
     * Retencion por tipo de dato (RL-11, RF-PR-03, tarea 2.10).
     *
     * AQUI NO ESTAN LOS ANOS DEL REGISTRO DE JORNADA NI DE `audit_log`, y no es
     * un olvido: son un umbral LEGAL y los sirve el perfil de cumplimiento del
     * centro (`compliance_profiles.retention_years`, RF-PD-07). La regla dura 14
     * es explicita —«los umbrales legales se leen del perfil de cumplimiento, no
     * son constantes»— y un `4` escrito en este fichero seria indistinguible de
     * uno configurado hasta que alguien comparase una purga con el convenio.
     *
     * LO QUE SI ESTA AQUI es el ciclo corto, que no es legal sino operativo:
     * cuanto historico tecnico quiere guardar quien administra el servidor. Los
     * 90 dias son los del doc 02 §8.2.1 y los del Anexo B.
     */
    'retention' => [

        /*
         * Log tecnico (RL-11). Variable propia y no la del historico de errores:
         * el Anexo B solo nombra `ERROR_HISTORY_RETENTION_DAYS`, pero son dos
         * almacenes distintos y un cliente puede querer conservar el fichero de
         * log mas o menos tiempo que la tabla. Los dos valen 90 de serie porque
         * es lo que dice el §8.2.1, no porque sean el mismo plazo.
         */
        'technical_log_days' => (int) env('TECHNICAL_LOG_RETENTION_DAYS', 90),

        /*
         * Historico de errores agrupado por huella (RF-PD-15, tabla `error_events`).
         *
         * SOLO EL PLAZO ES CONFIGURABLE. La tabla y la columna por la que
         * envejece —`error_events`.`last_seen_at`— estan fijas en
         * {@see \App\Modules\Compliance\Infrastructure\Persistence\DatabaseErrorHistoryArchive}:
         * las mismas filas las purgan dos comandos distintos
         * —`compliance:apply-retention` por este ciclo y `product:errors:prune`
         * por el repositorio de `Product`, con la columna escrita en su SQL— y
         * una variable de entorno que apuntara a otra tabla o a otra columna
         * dejaria a los dos purgando cosas distintas sin que nada lo dijera.
         */
        'error_history_days' => (int) env('ERROR_HISTORY_RETENTION_DAYS', 90),

        /*
         * Donde queda el informe de cada pasada, de propuesta o de purga
         * (RF-PR-03, regla dura 16). En el servidor del cliente y en ningun otro
         * sitio: el fabricante no accede a los datos del cliente (ADR-020).
         *
         * NO se limpia solo, al contrario que los temporales de la exportacion
         * legal: es la copia legible de la constancia de que se purgo, quien lo
         * autorizo y cuanto se llevo. Un cron que borrara los informes de purga
         * borraria justo lo que lee quien defiende la purga.
         *
         * POR DEFECTO EN `BACKUP_PATH/reports/retention` (ADR-045), junto a los
         * informes de `update.sh` y `restore.sh`, derivado de `BACKUP_PATH` como
         * el textfile de metricas de `observability`. Antes vivia en
         * storage/app, en la capa de cada contenedor: la propuesta semanal se
         * quedaba en `scheduler`, la purga real con `run --rm app` se perdia con
         * el contenedor y cualquier actualizacion borraba el resto. No llevan
         * datos personales (ambitos, tablas, recuentos, fechas, centro y token),
         * asi que pueden vivir sin cifrar; la poda de copias no toca `reports/`.
         *
         * LA CONSTANCIA CON VALOR ES EL ASIENTO `retention.purge_executed` de
         * `audit_log`, encadenado: el fichero es su copia legible y se contrasta
         * con el por el token de confirmacion que llevan los dos. Desde la 2.2.0
         * (A3-R2) `horizon` ya no puede escribir aqui, pero `app` y `scheduler`
         * si (son quienes purgan y proponen), asi que el fichero sigue sin probar
         * nada por si solo.
         *
         * `COMPLIANCE_RETENTION_REPORT_PATH` lo sigue pudiendo sobrescribir;
         * `product:doctor` avisa si apunta dentro de storage/app, que es una ruta
         * efimera para esto.
         */
        // EL UNICO RESOLVEDOR de esta ruta: lo leen el almacen de informes y la
        // sonda `files.retention_reports` de `product:doctor`. Sin definir o
        // VACIA (`COMPLIANCE_RETENTION_REPORT_PATH=`) vale lo mismo: el valor de
        // serie. Antes el vacio lo resolvia el almacen y la sonda comprobaba ''.
        'report_path' => rtrim(
            ((string) env('COMPLIANCE_RETENTION_REPORT_PATH', '')) !== ''
                ? (string) env('COMPLIANCE_RETENTION_REPORT_PATH')
                : rtrim((string) env('BACKUP_PATH', '/var/backups/fichaje'), '/').'/reports/retention',
            '/',
        ),

        /* Directorio del log tecnico. Se declara para poder apuntarlo en pruebas. */
        'technical_log_path' => env('COMPLIANCE_TECHNICAL_LOG_PATH', storage_path('logs')),

        /*
         * Filas por sentencia de borrado. Acota el tamano de cada `DELETE`, no la
         * transaccion: cuatro anos de una plantilla grande son cientos de miles
         * de filas, y un `IN` con todos los identificadores es una sentencia que
         * el planificador de PostgreSQL deja de optimizar.
         */
        'batch_size' => (int) env('COMPLIANCE_RETENTION_BATCH_SIZE', 1000),

        /*
         * Conexion con la que se sueltan las particiones de `audit_log`
         * (ADR-027, ADR-033). Es la del rol de MANTENIMIENTO, la unica que puede
         * hacerlo, y su credencial no vive en el `.env` de la aplicacion: la
         * aporta quien ejecuta la purga. La simulacion no la usa.
         */
        'maintenance_connection' => env('DB_MAINTENANCE_CONNECTION', 'pgsql_maintenance'),
    ],

];
