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
 * ## `absences` y `error_events`
 *
 * **No existen todavia en este esquema**, y el manifiesto las declara
 * `not_installed` en lugar de escribir dos ficheros vacios. La diferencia
 * importa: un `absences.csv` con cero filas le dice al cliente «no tienes
 * ausencias registradas», y lo cierto es «esta version no registra ausencias».
 * `error_events` llega con la tarea 5.12.
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
     */
    public const string SCHEMA_VERSION = '1';

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

            // --- La configuracion del producto --------------------------------

            ExportedDataset::json(
                'installation_settings',
                ['key', 'value', 'updated_at', 'updated_by_user_uuid'],
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
     * @return list<string>
     */
    public static function notInstalled(): array
    {
        return ['absences', 'error_events'];
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
