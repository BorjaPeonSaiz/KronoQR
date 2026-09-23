<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * **Que se lleva el cliente cuando pide todos sus datos** (**RF-PD-14**, RL-20,
 * decision 2 de la ficha 5.10).
 *
 * Este fichero es la respuesta entera a «¿que hay dentro del ZIP?». No hay otra
 * lista en ningun sitio: la consulta SQL de cada conjunto vive en
 * `Infrastructure\Export\DatabaseDataExportSource`, pero **lo que sale es lo que
 * esta declarado aqui**, porque el escritor aplica la lista de permitidos a cada
 * fila antes de escribirla.
 *
 * ## Las tres reglas que sigue cada linea
 *
 * 1. **Ni un secreto, ni un hash de credencial.** No aparecen —ni pueden
 *    aparecer, porque la lista es de permitidos— `employees.pin_hash`,
 *    `employees.national_id_hash`, `employees.photo_path`,
 *    `credentials.secret_hash`, `devices.token_hash`,
 *    `support_grants.token_hash`, `license.signed_key`, `users.password`,
 *    `users.remember_token`, `users.two_factor_secret` ni
 *    `scan_events.payload_fingerprint`. Los cinco primeros porque son lo que
 *    autentica; `signed_key` porque la clave es del fabricante y el cliente ya
 *    la tiene en su correo de activacion; `photo_path` porque es una ruta del
 *    servidor y la foto no es un dato del registro horario;
 *    `payload_fingerprint` porque es un derivado de un valor firmado y no aporta
 *    nada que `scan_id` no diga ya.
 *    Del segundo factor sale **si esta activo** (`two_factor_enabled`), que es
 *    informacion de configuracion, y nunca el secreto ni el contador.
 *
 * 2. **Ningun identificador interno (`BIGINT`).** Toda referencia entre ficheros
 *    va por `uuid` —`employee_uuid`, `device_uuid`, `shift_entry_uuid`,
 *    `user_uuid`, `credential_uuid`— igual que en toda la API (doc 01 §5.5). Los
 *    departamentos no tienen `uuid` en este esquema, asi que se referencian por
 *    su `name`, que es lo que el producto enseña y lo unico estable que hay.
 *
 *    **La unica excepcion es `audit_log`, y esta ahi para que el cliente pueda
 *    verificar la cadena fuera del producto** (RL-04). El hash de cada asiento se
 *    calcula sobre `actor_type#actor_id` y `subject_type#subject_id`: sustituir
 *    esos numeros por UUID entregaria un fichero que **no se puede verificar**,
 *    que es justo lo contrario de lo que RL-04 pide. Se entregan los dos: los
 *    identificadores tal como entraron en el hash y, ademas, `actor_uuid`
 *    resuelto, para que el fichero se pueda leer sin adivinar.
 *
 * 3. **Nada se oculta** (regla dura 5, RL-04). `shift_entries` lleva **todas las
 *    versiones** —`superseded` y `voided` incluidas— con su `version` y su
 *    `superseded_by_uuid`, y `shift_corrections` lleva el autor, el `before`, el
 *    `after`, el codigo y el texto del motivo. Una exportacion que enseñara solo
 *    la ultima version seria un registro horario reescrito, y la skill
 *    `/informe-nuevo` lo dice con todas las letras: un informe que oculte las
 *    correcciones no cumple.
 *
 * ## Lo que queda fuera, y por que
 *
 * - `personal_access_tokens` — son credenciales vivas. Exportarlas seria repartir
 *   sesiones en un fichero.
 * - `device_pairing_requests` — codigos de emparejamiento de un solo uso, con
 *   minutos de vida (5.6). No hay nada que conservar.
 * - `setup_progress` — el rastro del asistente, que ya consta en `audit_log`.
 * - `failed_jobs`, `migrations`, `cache`, `jobs` — fontaneria del framework, no
 *   datos del cliente.
 * - `model_has_roles`, `roles`, `permissions` — se entregan **resueltos** en la
 *   columna `roles` de `users.csv`, que es lo que responde la pregunta; las
 *   tablas de union son un detalle de implementacion de la libreria de permisos.
 *
 * ## Ya no hay ningun conjunto «no instalado»
 *
 * `absences` fue el ultimo, y entra como un conjunto mas desde la tarea 3.10,
 * que creo la tabla (RF-GP-04). Antes el manifiesto la declaraba
 * `not_installed` en lugar de escribir un fichero vacio, y la diferencia
 * importaba: un `absences.csv` con cero filas le dice al cliente «no tienes
 * ausencias registradas», y lo cierto era «esta version no registra ausencias».
 * `error_events` recorrio el mismo camino en la tarea 5.12.
 *
 * {@see self::notInstalled()} **sigue existiendo y devuelve una lista vacia**:
 * el mecanismo tiene que estar el dia que el producto declare una tabla antes de
 * implementarla, que es lo que ha pasado dos veces.
 *
 * ## `absences` lleva `note`, y es la excepcion que merece explicacion
 *
 * En todas las demas superficies del producto la nota de una ausencia se
 * protege: no viaja al `responsable_departamento`, no entra en `audit_log` —de
 * ella solo consta `has_note`— y no aparece en ningun log tecnico, porque una
 * baja medica es dato de salud (regla dura 21). Aqui si sale, por lo mismo que
 * `error_events` sale entero: **este ZIP se queda con el cliente**, que es el
 * responsable del tratamiento (RL-16) y a quien RL-20 obliga a entregar todos
 * sus datos. Lo que no puede salir son secretos y credenciales, no los datos
 * propios. La diferencia con el paquete de diagnostico es exactamente esa:
 * aquel va hacia el fabricante y por eso va anonimizado (ADR-020).
 *
 * Y salen **todas las versiones** —`superseded` y `voided` incluidas— con su
 * `version`, su `supersedes_uuid` y su motivo, igual que `shift_entries`: una
 * exportacion que enseñara solo la ultima version seria un registro reescrito.
 *
 * ## Dominio puro
 *
 * Sin framework, sin Eloquent y sin SQL: es una declaracion. Se puede leer
 * entera en una prueba unitaria sin base de datos, y esa prueba es la que fija
 * que los secretos no entren (`DataExportCatalogTest`).
 */
final class DataExportCatalog
{
    /**
     * La version del **formato** de la exportacion, no la del producto.
     *
     * Sube cuando cambia la forma de los ficheros —una columna que se va, un
     * fichero que se parte en dos—, no cuando se publica una version de KronoQR.
     * Va en `manifest.json` para que un cliente que guarda exportaciones de
     * varios años sepa por que dos de ellas no tienen las mismas columnas.
     *
     * **`2` desde la tarea 3.9**: el ZIP gana `report_exports.csv`, el registro
     * de los informes generados en segundo plano (RF-IN-06). Un fichero nuevo es
     * un cambio de forma, y quien compare una exportacion de este año con una del
     * anterior tiene que poder explicarse la diferencia sin abrir las dos.
     *
     * **`3` desde la tarea 3.12**: el ZIP gana `weekly_summary_deliveries.csv`,
     * el registro de los resumenes semanales que salieron por correo (RF-PR-05).
     * Mismo criterio que el salto anterior: un fichero mas es un cambio de
     * forma, aunque ninguna columna de las que ya habia se haya movido.
     */
    public const string SCHEMA_VERSION = '3';

    /**
     * Los conjuntos de datos que van al ZIP, **en el orden en que se escriben**.
     *
     * El orden es el de lectura de una persona: primero el centro y su
     * organizacion, despues la plantilla, despues el registro horario, despues
     * las evidencias y al final la configuracion del producto. No es cosmetico:
     * es el orden del `manifest.json` y el del `README.md`.
     *
     * @return list<ExportedDataset>
     */
    public static function datasets(): array
    {
        return [
            // --- El centro y su organizacion ---------------------------------

            ExportedDataset::json(
                'site',
                ['name', 'timezone', 'compliance_profile', 'settings', 'created_at'],
                ['settings'],
            ),

            ExportedDataset::csv('departments', [
                'name',
                'site_name',
                'manager_user_uuid',
            ]),

            // --- La plantilla -------------------------------------------------

            ExportedDataset::csv('employees', [
                'employee_uuid',
                'employee_code',
                'first_name',
                'last_name',
                'email',
                'department_name',
                'status',
                'hired_at',
                'terminated_at',
                'locale',
                'pin_issued_at',
                'pin_delivered_at',
                'pin_delivered_by_user_uuid',
                'created_at',
                'updated_at',
            ]),

            ExportedDataset::csv('employment_contracts', [
                'employee_uuid',
                'weekly_hours',
                'annual_hours',
                'schedule_type',
                'valid_from',
                'valid_to',
                'created_at',
                'created_by_user_uuid',
            ]),

            /*
             * Las ausencias registradas (RF-GP-04, tarea 3.10).
             *
             * **Todas las versiones** —`superseded` y `voided` incluidas— con su
             * `version`, su `supersedes_uuid`, su motivo de cambio y su
             * anulacion, igual que `shift_entries` y por la misma razon (regla
             * dura 5, RL-04).
             *
             * **`note` sale**: ver el docblock de la clase. Es la exportacion de
             * los datos del propio cliente (RL-16, RL-20), no un paquete que
             * viaje al fabricante.
             */
            ExportedDataset::csv('absences', [
                'uuid',
                'employee_uuid',
                'type',
                'starts_on',
                'ends_on',
                'note',
                'status',
                'version',
                'supersedes_uuid',
                'change_reason',
                'voided_at',
                'void_reason',
                'created_at',
                'created_by_user_uuid',
            ]),

            ExportedDataset::csv('credentials', [
                'credential_uuid',
                'employee_uuid',
                'key_id',
                'issued_at',
                'printed_at',
                'delivered_at',
                'delivered_by_user_uuid',
                'revoked_at',
                'revoked_reason',
            ]),

            ExportedDataset::csv('devices', [
                'device_uuid',
                'name',
                'site_name',
                'app_version',
                'status',
                'pending_queue_size',
                'paired_at',
                'last_seen_at',
                'created_at',
                'updated_at',
            ]),

            // --- El registro horario ------------------------------------------

            ExportedDataset::csv('shift_entries', [
                'shift_entry_uuid',
                'employee_uuid',
                'site_name',
                'work_date',
                'clocked_in_at',
                'clocked_out_at',
                'duration_minutes',
                'status',
                'clock_in_source',
                'clock_out_source',
                'version',
                'superseded_by_uuid',
                'created_at',
                'updated_at',
            ]),

            ExportedDataset::csv('shift_corrections', [
                'shift_entry_uuid',
                'action',
                'performed_by_user_uuid',
                'performed_by_name',
                'reason_code',
                'reason_text',
                'before',
                'after',
                'created_at',
            ]),

            ExportedDataset::csv('daily_totals', [
                'employee_uuid',
                'work_date',
                'total_minutes',
                'shift_count',
                'first_in_at',
                'last_out_at',
                'has_open_shift',
                'has_incident',
                'recalculated_at',
            ]),

            ExportedDataset::csv('incidents', [
                'employee_uuid',
                'work_date',
                'shift_entry_uuid',
                'type',
                'severity',
                'status',
                'detected_at',
                'context',
                'assigned_to_user_uuid',
                'notified_at',
                'resolved_at',
                'resolved_by_user_uuid',
                'resolution_note',
                'created_at',
                'updated_at',
            ]),

            ExportedDataset::csv('scan_events', [
                'scan_id',
                'device_uuid',
                'employee_uuid',
                'occurred_at',
                'recorded_at',
                'origin',
                'intent',
                'result',
                'shift_entry_uuid',
                'worked_minutes',
                'clock_skew_seconds',
                'flagged_for_review',
                'client_meta',
            ]),

            // --- Las evidencias -----------------------------------------------

            ExportedDataset::csv('audit_log', [
                'id',
                'occurred_at',
                'actor_type',
                'actor_id',
                'actor_uuid',
                'action',
                'subject_type',
                'subject_id',
                'payload',
                'prev_hash',
                'hash',
                'ip',
                'user_agent',
            ]),

            ExportedDataset::csv('audit_chain_anchors', [
                'partition_year',
                'first_hash',
                'last_hash',
                'row_count',
                'sealed_at',
                'sealed_by',
            ]),

            // --- Las cuentas y los accesos ------------------------------------

            ExportedDataset::csv('users', [
                'user_uuid',
                'name',
                'email',
                'roles',
                'locale',
                'is_active',
                'two_factor_enabled',
                'last_login_at',
                'created_at',
                'updated_at',
            ]),

            ExportedDataset::csv('support_grants', [
                'support_grant_uuid',
                'granted_by_user_uuid',
                'reason',
                'scope',
                'granted_at',
                'expires_at',
                'revoked_at',
                'revoked_by_user_uuid',
                'accessed_at',
            ]),

            // --- El diagnostico tecnico ---------------------------------------

            /*
             * El historico de errores agrupado por huella (RF-PD-15).
             *
             * **Entra entero porque son datos del cliente**, y esa es la
             * diferencia con el paquete de diagnostico: aquel va anonimizado
             * porque sale hacia el fabricante (ADR-020) y alli el autor de la
             * resolucion no viaja; aqui se queda con el cliente, que es su
             * responsable del tratamiento (RL-16), asi que sale todo — incluido
             * quien dio cada fallo por atendido.
             *
             * La columna `fingerprint` sale tambien, y no es ruido tecnico: es
             * lo que permite a quien lea el fichero anos despues entender por
             * que hay una fila con `occurrences = 1000` en lugar de mil filas.
             *
             * `resolved_by_user_uuid` y no `resolved_by_user_id`, como toda
             * referencia a `users` en esta exportacion: ningun identificador
             * interno sale del producto (doc 01 §5.5).
             */
            ExportedDataset::csv('error_events', [
                'fingerprint',
                'level',
                'source',
                'module',
                'code',
                'message',
                'exception_class',
                'file',
                'line',
                'context',
                'trace_id',
                'device_id',
                'employee_uuid',
                'app_version',
                'occurrences',
                'first_seen_at',
                'last_seen_at',
                'resolved_at',
                'resolved_by_user_uuid',
            ]),

            /*
             * LOS INFORMES GENERADOS EN SEGUNDO PLANO (tarea 3.9, RF-IN-06).
             *
             * Sale el **registro** de que se generaron, no los ficheros: el ZIP de
             * RL-20 lleva los datos del cliente, y el contenido de cada informe ya
             * esta ahi —`shift_entries`, `daily_totals`, `employment_contracts`—.
             * Reempaquetar cada CSV generado en los ultimos años seria multiplicar
             * la misma informacion y hacer el ZIP inmanejable.
             *
             * Lo que si responde este fichero es «¿que informes con horas de mi
             * plantilla salieron de aqui, quien los pidio y quien se los llevo?»,
             * que es exactamente lo que la fila conserva para siempre (regla dura
             * 5) y lo que una revision de accesos pregunta.
             *
             * **Fuera tres columnas y las tres por el mismo motivo**: `id` es la
             * clave interna y ningun identificador interno sale del producto (doc
             * 01 §5.5); `file_path` es una ruta del servidor —topologia de la
             * maquina del cliente, inutil fuera y util para quien no deberia—; y
             * `download_token_hash` es material de un secreto vivo, igual que
             * `pin_hash` o `token_hash`, y un ZIP se guarda y se reenvia durante
             * años.
             *
             * `requested_by_user_uuid` y no `requested_by_user_id`, como toda
             * referencia a `users` en esta exportacion.
             *
             * Las filas ya purgadas salen **minimizadas**, que es como estan
             * guardadas (RL-11): sin `scope` y sin los filtros por persona o
             * departamento dentro de `parameters`.
             */
            ExportedDataset::csv('report_exports', [
                'uuid',
                'kind',
                'format',
                'status',
                'parameters',
                'scope',
                'requested_by_user_uuid',
                'requested_at',
                'started_at',
                'completed_at',
                'failed_at',
                'failure_reason',
                'file_name',
                'size_bytes',
                'sha256',
                'row_count',
                'criteria',
                'expires_at',
                'purged_at',
                'downloaded_at',
                'download_count',
                'notified_at',
                'notification_channel',
            ]),

            /*
             * LOS RESUMENES SEMANALES QUE SALIERON POR CORREO (tarea 3.12,
             * RF-PR-05).
             *
             * Va justo detras de los informes en diferido porque contesta la
             * misma clase de pregunta —«¿que informacion sobre mi plantilla ha
             * salido de aqui, a quien y cuando?»— con la diferencia de que esto
             * **sale solo**, cada lunes y sin que nadie pulse nada. Es
             * precisamente lo que un cliente que se lleva sus datos necesita
             * poder reconstruir sin depender de que nadie le cuente como estaba
             * configurado el producto.
             *
             * **No lleva el contenido del correo**, por lo mismo que el conjunto
             * de arriba no lleva los ficheros: las horas que iban dentro ya estan
             * en este ZIP, en `daily_totals.csv` y `shift_entries.csv`. Aqui esta
             * el hecho del envio.
             *
             * **Fuera `id`**, la clave interna: ningun identificador interno sale
             * del producto (doc 01 §5.5). `manager_user_uuid` y no
             * `manager_user_id`, como toda referencia a `users` en esta
             * exportacion.
             *
             * **Ni un solo dato de ningun empleado**, y no es una omision de este
             * catalogo sino de la tabla: lo que se guarda son recuentos. De quien
             * eran las horas que salieron por correo consta en `audit_log.csv`,
             * que tambien va en este ZIP.
             */
            ExportedDataset::csv('weekly_summary_deliveries', [
                'manager_user_uuid',
                'week_start',
                'sent_at',
                'employee_count',
                'row_count',
                'created_at',
            ]),

            // --- La configuracion del producto --------------------------------

            /*
             * La configuracion de la instalacion, con **una excepcion a «sale
             * todo»** (tarea 3.3, decision 1 de la segunda vuelta).
             *
             * Las claves marcadas `confidential` en {@see SettingDefinition}
             * —hoy solo `KIOSK_SERVICE_CODE`— salen con `value` nulo y
             * `value_redacted: true`. No es una exclusion silenciosa: la fila
             * esta, se ve cuando se cambio y quien lo hizo, y la columna dice
             * con todas las letras que el valor no viene.
             *
             * **Por que aqui si y en el resto no.** Este ZIP se queda con el
             * cliente (RL-16) y por eso lleva sus datos personales enteros; pero
             * un ZIP se guarda, se reenvia por correo y se archiva años, y lo
             * que hay dentro de esa clave es el codigo con el que se abre la
             * pantalla de mantenimiento de todas sus tablets (RF-KI-08). Es el
             * mismo criterio con el que no salen `pin_hash` ni `token_hash`: un
             * secreto vivo no es un dato del registro horario. Y es la misma
             * decision que ya toma el asiento de `audit_log` de su propio
             * cambio, para que las dos evidencias digan lo mismo.
             *
             * El valor sigue estando donde el cliente lo escribio: `GET
             * /api/v1/settings` en la pantalla «Ajustes operativos».
             */
            ExportedDataset::json(
                'installation_settings',
                ['key', 'value', 'value_redacted', 'updated_at', 'updated_by_user_uuid'],
                ['value'],
            ),

            ExportedDataset::json(
                'compliance_profiles',
                [
                    'name',
                    'jurisdiction',
                    'retention_years',
                    'min_rest_hours',
                    'max_daily_hours',
                    'max_weekly_hours',
                    'break_required_after_hours',
                    'week_starts_on',
                    'holiday_calendar',
                    'is_default',
                    'updated_at',
                    'updated_by_user_uuid',
                ],
                ['holiday_calendar'],
            ),

            ExportedDataset::json(
                'license',
                [
                    'license_id',
                    'customer_name',
                    'plan',
                    'max_employees',
                    'max_devices',
                    'features',
                    'valid_from',
                    'valid_until',
                    'issued_at',
                    'activated_at',
                    'activated_by_user_uuid',
                    'last_verified_at',
                ],
                ['features'],
            ),
        ];
    }

    /**
     * Lo que esta version **no** registra todavia. Ver el docblock de la clase:
     * `not_installed` en el manifiesto no es lo mismo que un fichero vacio.
     *
     * **Hoy esta vacia**, y el metodo se queda. `error_events` salio de aqui en
     * la tarea 5.12 y `absences` en la 3.10; que el producto declare una tabla
     * en el doc 01 antes de implementarla ha pasado dos veces, y el dia que
     * vuelva a pasar el mecanismo tiene que estar. Quitarlo obligaria ademas a
     * tocar el manifiesto, la guia del ZIP y sus dos traducciones.
     *
     * @return list<string>
     */
    public static function notInstalled(): array
    {
        return [];
    }

    /**
     * Las columnas que NUNCA pueden aparecer en ningun conjunto.
     *
     * Existe para la prueba unitaria, y es una red **ademas** de la lista de
     * permitidos, no en su lugar: la lista ya impide que entren, y esto convierte
     * el intento en un fallo con nombre en vez de en una columna vacia que nadie
     * mira. Si algun dia alguien añade `pin_hash` «para depurar», la prueba dice
     * exactamente cual es el problema.
     *
     * @return list<string>
     */
    public static function forbiddenColumns(): array
    {
        return [
            'pin_hash',
            'national_id_hash',
            'photo_path',
            'secret_hash',
            'token_hash',
            'signed_key',
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_last_slice',
            'payload_fingerprint',
        ];
    }

    /**
     * El conjunto con ese nombre, o `null`.
     *
     * Lo usa el escritor para recorrer, y la prueba para preguntar por uno
     * concreto sin depender de su posicion en la lista.
     */
    public static function dataset(string $name): ?ExportedDataset
    {
        foreach (self::datasets() as $dataset) {
            if ($dataset->name === $name) {
                return $dataset;
            }
        }

        return null;
    }

    /**
     * Los nombres, en orden.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (ExportedDataset $dataset): string => $dataset->name, self::datasets());
    }
}
