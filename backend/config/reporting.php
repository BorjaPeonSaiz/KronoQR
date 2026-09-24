<?php

declare(strict_types=1);

/*
 * Informes por periodo (RF-IN-01..03, tarea 2.8).
 *
 * NADA ESPECIFICO DE UN CLIENTE VIVE EN EL CODIGO (ADR-017, regla dura 13). Los
 * tres numeros de aqui son presupuestos de RECURSOS, no reglas de negocio ni
 * umbrales legales —esos se leen del perfil de cumplimiento (regla dura 14)—, y
 * un cliente con un servidor mas grande o una plantilla mayor los ajusta en su
 * `.env` sin tocar el repositorio.
 *
 * LOS TRES DEFIENDEN LO MISMO: que un informe no se coma la base de datos que
 * atiende el fichaje (RNF-P-02, regla dura 19). Se disparan a distinta altura
 * porque miden cosas distintas —cuanto calendario, cuantas filas y cuanto
 * tiempo— y ninguno sustituye a los otros: un rango corto sobre una plantilla
 * enorme pasa el primero y no el segundo, y un plan de consulta que se degrada
 * pasa los dos y no el tercero.
 */

return [

    'period' => [

        /*
         * Techo del rango que se entrega en el acto, en dias.
         *
         * Tres meses, que es el criterio literal del paso 5 de `/informe-nuevo`:
         * mas de eso va a la generacion en diferido de RF-IN-06 (tarea 3.9). Se
         * comprueba ANTES de tocar la base de datos, que es lo barato.
         *
         * No sustituye al techo de `DateRange::MAXIMUM_DAYS` (366): aquel es el
         * limite del objeto de dominio para cualquier consulta de jornadas, y
         * este es el presupuesto sincrono de ESTE informe, que es mas caro
         * porque cruza la plantilla entera con el calendario.
         */
        'max_range_days' => (int) env('REPORTING_PERIOD_MAX_RANGE_DAYS', 92),

        /*
         * Techo de filas del resultado: sujetos x cubos de periodo.
         *
         * Veinte mil es, con la granularidad diaria, la plantilla de 500
         * personas durante cuarenta dias, o la de 200 durante un trimestre. Por
         * encima de eso, la respuesta ya no es una tabla que alguien lee: es una
         * exportacion, y para eso estan la tarea 2.9 y RF-IN-06.
         *
         * Se estima ANTES de ejecutar, contando sujetos del alcance y cubos del
         * rango. Un `LIMIT` no serviria: recortar en silencio un informe de
         * horas es peor que no darlo, porque nadie ve que falta media plantilla.
         */
        'max_rows' => (int) env('REPORTING_PERIOD_MAX_ROWS', 20000),

        /*
         * `statement_timeout` de la consulta del informe, en segundos.
         *
         * Diez, que es el umbral del paso 5 de `/informe-nuevo` por encima del
         * cual el informe deja de ser sincrono. Se aplica con `SET LOCAL` en la
         * transaccion de la consulta y no con un cronometro en PHP: asi lo corta
         * PostgreSQL y libera la conexion, en lugar de descubrir tarde que la
         * consulta lleva cuarenta segundos ocupando la base de datos que
         * atiende el fichaje.
         *
         * Se aplica SOLO a esta consulta. Un `statement_timeout` global cortaria
         * migraciones y reconciliaciones que legitimamente tardan mas.
         */
        'statement_timeout_seconds' => (int) env('REPORTING_PERIOD_TIMEOUT_SECONDS', 10),
    ],

    /*
     * VISTA DE CUMPLIMIENTO (RF-PA-06, tarea 3.4).
     *
     * Los dos son presupuestos de RECURSOS, como los del informe por periodo.
     * LOS UMBRALES LEGALES NO ESTAN AQUI y no pueden estarlo: descanso minimo,
     * jornada diaria, jornada semanal y pausa se leen del perfil de cumplimiento
     * (`compliance_profiles`, regla dura 14, ADR-017). Si algun dia apareciera un
     * `12` en este fichero, seria un umbral legal escondido en el repositorio.
     */
    'compliance' => [

        /*
         * Techo del rango que se entrega en el acto, en dias.
         *
         * Tres meses, el mismo presupuesto sincrono que el informe por periodo y
         * por el mismo motivo: son las dos consultas del producto que cruzan la
         * plantilla entera con el calendario. No sustituye al techo de
         * `DateRange::MAXIMUM_DAYS` (366), que es el limite del objeto de dominio
         * para cualquier consulta de jornadas.
         *
         * Se comprueba sobre el rango YA RESUELTO —con la omision aplicada— y
         * antes de tocar la base de datos, que es lo barato.
         */
        'max_range_days' => (int) env('REPORTING_COMPLIANCE_MAX_RANGE_DAYS', 92),

        /*
         * `statement_timeout` de la consulta de hechos, en segundos.
         *
         * Diez, como el informe. Se aplica con `SET LOCAL` en la transaccion de la
         * consulta y no con un cronometro en PHP: asi lo corta PostgreSQL y libera
         * la conexion, en lugar de descubrir tarde que la consulta lleva cuarenta
         * segundos ocupando la base de datos que atiende el fichaje (RNF-P-02,
         * regla dura 19). La cancelacion sale como el mismo `422` del informe.
         */
        'statement_timeout_seconds' => (int) env('REPORTING_COMPLIANCE_TIMEOUT_SECONDS', 10),
    ],

    /*
     * CUADRO DE IMPACTO Y ADOPCION (RF-IN-08, RNF-D-01, tarea 3.13).
     *
     * Los dos son presupuestos de RECURSOS. LOS OBJETIVOS DEL §1.3 NO ESTAN AQUI
     * —«≥ 99 % de jornadas completas», «≥ 99,9 % de disponibilidad»— y no pueden
     * estarlo: son la ambicion declarada del producto, la misma para todos los
     * clientes, y viven en `AdoptionIndicatorKey::target()`. Ni la linea base de
     * horas/mes, que es un dato que declara cada cliente y vive en
     * `installation_settings` (`BASELINE_MANUAL_HOURS_PER_MONTH`).
     */
    'adoption' => [

        /*
         * Techo del rango que se entrega en el acto, en dias.
         *
         * **Un año, y no los tres meses de sus dos hermanas.** La diferencia es
         * deliberada: aquellas producen una fila por persona y dia —o por persona y
         * jornada—, asi que el rango es proporcional al tamaño de la respuesta.
         * Este cuadro devuelve **doce indicadores y cuatro origenes** por mucho
         * volumen que haya detras, y el caso ancho real es «el año pasado
         * completo», que es exactamente la pregunta con la que se renueva una
         * licencia.
         *
         * Coincide con `DateRange::MAXIMUM_DAYS` (366) a proposito: por encima no
         * hay rango que construir, asi que subirlo en el `.env` no daria mas
         * alcance, solo un error distinto.
         */
        'max_range_days' => (int) env('REPORTING_ADOPTION_MAX_RANGE_DAYS', 366),

        /*
         * `statement_timeout` de la consulta de hechos, en segundos.
         *
         * Diez, como las otras dos, y por lo mismo: la consulta corre contra la
         * base de datos por la que pasa cada fichaje (ADR-010, RNF-P-02, regla dura
         * 19), asi que quien la corta tiene que ser PostgreSQL —que libera la
         * conexion— y no un cronometro en PHP. La cancelacion sale como `422`, con
         * el mismo consejo que el techo de rango: acortar el periodo.
         */
        'statement_timeout_seconds' => (int) env('REPORTING_ADOPTION_TIMEOUT_SECONDS', 10),
    ],

    /*
     * INFORMES EN DIFERIDO (RF-IN-06, RF-IN-07, ADR-041, tarea 3.9).
     *
     * Los seis son presupuestos de RECURSOS y plazos de RETENCION, nunca reglas
     * de negocio: un cliente con un servidor mas grande, una plantilla mayor o
     * una politica de retencion propia los ajusta en su `.env` sin tocar el
     * repositorio (ADR-017, regla dura 13). LOS UMBRALES LEGALES NO ESTAN AQUI
     * —se leen del perfil de cumplimiento (regla dura 14)—, y el FORMATO de la
     * salida a nomina tampoco: ese vive en los ajustes con ambito de la 5.1,
     * porque lo cambia una persona desde el panel y no quien administra el
     * servidor.
     */
    'export' => [

        /*
         * Donde se escriben los ficheros generados en diferido.
         *
         * **Fuera de `public/`**, y eso no es configurable en la practica: el
         * fichero lleva las horas de personas identificadas, y servido por el
         * servidor web sin pasar por la aplicacion no habria enlace de un solo
         * uso, ni caducidad, ni asiento en `audit_log` — habria una URL
         * adivinable. El unico camino hacia esos bytes es
         * `GET /reports/exports/{uuid}/download` con su token (ADR-041).
         *
         * Directorio a `0700` y fichero a `0600`, como la exportacion integra.
         */
        'path' => env('REPORTING_EXPORT_PATH', storage_path('app/reports')),

        /*
         * Dias que vive el fichero antes de que la purga diaria lo borre.
         *
         * Siete, el mismo plazo que la exportacion integra y el paquete de
         * diagnostico con datos personales, y por el mismo motivo: la retencion
         * de un fichero con datos de la plantilla no puede depender de que
         * alguien se acuerde de borrarlo. Quien lo necesite mas tiempo lo saca
         * del servidor, que es lo que se espera que haga con el.
         *
         * **La fila no se borra nunca** (regla dura 5): pasa a `purged` y se
         * queda con sus fechas, su huella y su recuento.
         */
        'retention_days' => (int) env('REPORTING_EXPORT_RETENTION_DAYS', 7),

        /*
         * Minutos que vive el enlace de descarga (ADR-041).
         *
         * Quince. El enlace va **sin sesion** —un clic desde la pantalla no lleva
         * cabecera `Authorization`— y esa es exactamente la razon por la que tiene
         * que ser corto ademas de de un solo uso. Quince minutos es de sobra para
         * pulsar el boton y que la descarga empiece, y poco para que sirva de algo
         * en un historial de navegador compartido.
         *
         * Subirlo no da mas comodidad: cada consulta del estado acuña uno nuevo.
         */
        'link_ttl_minutes' => (int) env('REPORTING_EXPORT_LINK_TTL_MINUTES', 15),

        /*
         * `statement_timeout` de la consulta **en diferido**, en segundos.
         *
         * Diez minutos, sesenta veces el techo sincrono, y no es lo mismo que «sin
         * techo»: la consulta corre contra la base de datos por la que pasa cada
         * fichaje (ADR-010, RNF-P-02, regla dura 19), asi que quien la corta tiene
         * que ser PostgreSQL —que libera la conexion— y no el trabajador, que la
         * dejaria colgando.
         *
         * Se aplica SOLO a esta consulta, con `SET LOCAL` en su transaccion. La
         * cancelacion deja la fila en `failed` con motivo `query_timeout`, que en
         * la pantalla se lee como «pide dos periodos mas cortos».
         */
        'statement_timeout_seconds' => (int) env('REPORTING_EXPORT_TIMEOUT_SECONDS', 600),

        /*
         * Segundos tras los cuales un informe sin terminar se declara ATASCADO y
         * pasa a `failed` con motivo `stale`.
         *
         * Una hora, el doble largo que el `statement_timeout` de arriba mas el
         * margen de escribir el fichero: **por debajo de lo que tarda el informe
         * mas grande, se daria por muerto uno que sigue escribiendo**.
         *
         * PARA QUE SIRVE: solo puede haber un informe en curso **por persona**. Si
         * el servidor se para mientras se genera uno —una actualizacion, un corte
         * de luz—, esa fila se quedaria «en curso» para siempre y esa persona no
         * podria pedir otro. Pasado este plazo, la proxima vez que pida uno —o en
         * la purga de la madrugada siguiente— la fila se marca como fallida y se
         * desbloquea sola.
         */
        'stale_after_seconds' => (int) env('REPORTING_EXPORT_STALE_AFTER', 3600),

        /*
         * Peticiones por minuto a la ruta de descarga, POR DIRECCION IP.
         *
         * Treinta, la misma cifra que la zona de la exportacion integra. Es por IP
         * y no por cuenta porque **esta ruta no tiene sesion** (ADR-041): no hay
         * cuenta por la que contar. Es la unica defensa de volumen que queda, y lo
         * que impide que alguien que conozca un `uuid` pruebe tokens a la
         * velocidad de la red; el resto de la defensa es el tamaño del secreto.
         *
         * No lo bajes a tres como el diagnostico: en un hotel, recepcion y
         * direccion salen a internet por la misma IP publica, y tres personas
         * descargando informes a la vez se cortarian entre si.
         */
        'download_rate_limit_per_minute' => (int) env('REPORTING_EXPORT_DOWNLOAD_RATE_LIMIT', 30),
    ],
];
