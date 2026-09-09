<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\DataExportSource;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;
use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Las consultas que producen la exportacion integra (**RF-PD-14**, RL-20,
 * decision 3 de la ficha 5.10).
 *
 * ## SQL escrito a mano, y no Eloquent
 *
 * Por lo mismo que `DatabaseLegalExportSource`: lo que hace falta no son los
 * agregados con sus invariantes, es un **modelo de lectura plano**. Y ademas
 * `Product` no puede importar los modelos de `Attendance`, `Workforce`,
 * `Identity` ni `Compliance` (doc 02 §1.6, verificado por Deptrac), asi que un
 * modelo propio sobre las mismas tablas seria una segunda definicion del mismo
 * esquema.
 *
 * ## Cursor de servidor, no un `SELECT` completo
 *
 * `DECLARE ... NO SCROLL CURSOR` y `FETCH FORWARD` por lotes. Es la unica forma
 * real de cumplir lo que el doc 02 §3.1 exige de las exportaciones: el driver de
 * PostgreSQL trae al cliente el resultado **entero** de un `SELECT` normal, asi
 * que un generador sobre `cursor()` seria streaming de mentira —las decenas de
 * miles de filas ya en memoria antes de ceder la primera—. Con el cursor, en el
 * proceso hay un lote cada vez.
 *
 * Un cursor sin `HOLD` **exige transaccion**, y la abre {@see self::within()},
 * que ademas eleva el nivel de aislamiento a `REPEATABLE READ`. Las dos cosas
 * hacen falta: la transaccion para que el cursor exista, y el nivel para que los
 * dieciocho cursores vean **la misma instantanea** — en `READ COMMITTED` cada
 * uno tomaria la suya al declararse y el ZIP saldria internamente incoherente.
 *
 * ## Todo sale ya formateado desde SQL
 *
 * Los instantes como ISO-8601 en UTC con microsegundos (regla dura 3), las
 * fechas como `YYYY-MM-DD`, los booleanos como `true`/`false` y los documentos
 * `jsonb` como su texto. Formatear en PHP obligaria a saber el tipo de cada
 * columna en dos sitios, y `to_char` no depende de como este compilado el
 * driver —que es lo que hace que un `integer` de PostgreSQL llegue unas veces
 * como `int` y otras como `string`—.
 *
 * ## Ni un identificador interno, salvo en `audit_log`
 *
 * Toda referencia entre ficheros se resuelve a `uuid` con un `JOIN` (doc 01
 * §5.5). La excepcion es `audit_log`, cuyo hash se calcula **sobre** `actor_id`
 * y `subject_id`: sustituirlos entregaria un fichero imposible de verificar, que
 * es lo contrario de lo que pide RL-04. Se entregan los dos —el numero que entro
 * en el hash y el `uuid` resuelto— para que el fichero sea verificable *y*
 * legible.
 *
 * ## Ninguna consulta filtra nada
 *
 * Ni por fecha, ni por estado, ni por empleado. Es el requisito: **integra**.
 * `shift_entries` incluye `superseded` y `voided`, `scan_events` incluye los
 * rechazados, y `credentials` incluye las revocadas. Un `WHERE` de mas aqui
 * seria un registro horario reescrito (regla dura 5, RL-04).
 */
final readonly class DatabaseDataExportSource implements DataExportSource
{
    /**
     * Filas por `FETCH`. Quinientas caben de sobra en memoria y ahorran ida y
     * vuelta; el numero no cambia el resultado, solo cuantas veces se habla con
     * la base de datos.
     */
    private const int FETCH_SIZE = 500;

    public function __construct(private ConnectionInterface $connection) {}

    public function within(Closure $work): mixed
    {
        /*
         * Si ya hay una transaccion abierta, esta sera **anidada** —un
         * `SAVEPOINT`— y PostgreSQL rechaza `SET TRANSACTION` en cuanto la
         * transaccion ha ejecutado una consulta (`25001`). Se comprueba ANTES de
         * abrir, que es el unico momento en el que la respuesta es fiable.
         *
         * En produccion siempre es cero: el caso de uso llama a `within()` fuera
         * de cualquier transaccion, y es lo que hace que la garantia de
         * instantanea unica se cumpla siempre que importa. El caso anidado es la
         * suite, donde `RefreshDatabase` envuelve cada prueba: ahi la instantanea
         * la define la transaccion de fuera y esta no puede —ni debe— cambiarla.
         *
         * Por eso la prueba que demuestra la garantia (`DataExportSnapshotTest`)
         * usa `CommittedDatabase` y no `RefreshDatabase`: sin transaccion
         * envolvente, se ejercita el camino real.
         */
        $outermost = $this->connection->transactionLevel() === 0;

        return $this->connection->transaction(function () use ($work, $outermost): mixed {
            /*
             * **`REPEATABLE READ`, y es la linea que hace veraz al manifiesto.**
             *
             * En `READ COMMITTED` —el nivel por omision de PostgreSQL, y el que
             * usa este producto porque es el correcto para el camino de fichaje—
             * la instantanea se toma **por sentencia**, no por transaccion. Cada
             * `DECLARE ... CURSOR` de los dieciocho conjuntos tomaria la suya al
             * declararse, asi que un fichaje ocurrido mientras se escribe el ZIP
             * aparece en unos ficheros y falta en otros: `scan_events.csv` con un
             * `shift_entry_uuid` que no esta en `shift_entries.csv`, o
             * `daily_totals.csv` que no cuadra con los tramos.
             *
             * Eso convierte la copia de respaldo de un registro con valor legal en
             * un documento internamente incoherente, y el hotel exporta a las
             * 06:00 igual que a las 22:00. Elevar el nivel hace que todos los
             * cursores vean el mismo instante.
             *
             * Se emite **dentro** de la transaccion y como primera sentencia:
             * PostgreSQL solo admite `SET TRANSACTION` antes de cualquier
             * consulta, de modo que ponerlo mas tarde fallaria con `25001`.
             *
             * **No `SERIALIZABLE`**: esto solo lee y no compite con nadie por
             * escribir, asi que no hay nada que serializar; lo unico que añadiria
             * son fallos `40001` que obligarian a reintentar una exportacion de
             * gigabytes.
             */
            if ($outermost) {
                $this->connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $work();
        });
    }

    public function rows(ExportedDataset $dataset): iterable
    {
        $cursor = 'kronoqr_data_export_'.bin2hex(random_bytes(8));

        $this->connection->statement('DECLARE '.$cursor.' NO SCROLL CURSOR FOR '.self::sqlFor($dataset->name));

        try {
            while (true) {
                /** @var list<object> $rows */
                $rows = $this->connection->select('FETCH FORWARD '.self::FETCH_SIZE.' FROM '.$cursor);

                if ($rows === []) {
                    return;
                }

                foreach ($rows as $row) {
                    /** @var array<string, mixed> $columns */
                    $columns = (array) $row;

                    yield $columns;
                }
            }
        } finally {
            $this->connection->statement('CLOSE '.$cursor);
        }
    }

    public function siteTimezone(): string
    {
        /** @var list<object{timezone: string}> $rows */
        $rows = $this->connection->select('SELECT timezone FROM sites ORDER BY id LIMIT 1');

        // Sin centro no hay zona, y eso solo pasa en una instalacion que aun no
        // ha pasado el asistente: UTC es lo que hay dentro, y es la verdad
        // (regla dura 3).
        return $rows === [] ? 'UTC' : $rows[0]->timezone;
    }

    /**
     * La consulta de un conjunto.
     *
     * Un mapa y no un `match` de dieciocho ramas: la correspondencia es
     * exactamente la del nombre y un `match` caso por caso disparaba la regla de
     * complejidad ciclomatica del §3.5 sin que hubiera ninguna complejidad real
     * que repartir. Es el mismo criterio con el que `AuditAction` resuelve la
     * familia de cada accion.
     *
     * Un conjunto del catalogo sin consulta falla aqui, en voz alta, en lugar de
     * producir un fichero vacio que nadie mira.
     */
    private static function sqlFor(string $dataset): string
    {
        return self::SQL_BY_DATASET[$dataset] ?? throw new RuntimeException(
            'El conjunto «'.$dataset.'» esta en el catalogo de la exportacion integra y no tiene consulta. '
            .'Añadela en DatabaseDataExportSource.'
        );
    }

    /**
     * La consulta de cada conjunto, por su nombre en `DataExportCatalog`.
     *
     * @var array<string, string>
     */
    private const array SQL_BY_DATASET = [
        'site' => self::SITE,
        'departments' => self::DEPARTMENTS,
        'employees' => self::EMPLOYEES,
        'employment_contracts' => self::EMPLOYMENT_CONTRACTS,
        'credentials' => self::CREDENTIALS,
        'devices' => self::DEVICES,
        'shift_entries' => self::SHIFT_ENTRIES,
        'shift_corrections' => self::SHIFT_CORRECTIONS,
        'daily_totals' => self::DAILY_TOTALS,
        'incidents' => self::INCIDENTS,
        'scan_events' => self::SCAN_EVENTS,
        'audit_log' => self::AUDIT_LOG,
        'audit_chain_anchors' => self::AUDIT_CHAIN_ANCHORS,
        'users' => self::USERS,
        'support_grants' => self::SUPPORT_GRANTS,
        'error_events' => self::ERROR_EVENTS,
        'installation_settings' => self::INSTALLATION_SETTINGS,
        'compliance_profiles' => self::COMPLIANCE_PROFILES,
        'license' => self::LICENSE,
    ];

    // -------------------------------------------------------------------------
    // Las consultas. Una por conjunto, en el orden de `DataExportCatalog`.
    //
    // `ORDER BY` por la clave interna en todas: es el unico orden estable y
    // barato, y hace que dos exportaciones de la misma instalacion se puedan
    // comparar linea a linea con `diff`.
    // -------------------------------------------------------------------------

    private const string SITE = <<<'SQL'
        SELECT s.name,
               s.timezone,
               cp.name                                                                AS compliance_profile,
               s.settings::text                                                       AS settings,
               to_char(s.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at
          FROM sites s
          LEFT JOIN compliance_profiles cp ON cp.id = s.compliance_profile_id
         ORDER BY s.id
        SQL;

    private const string DEPARTMENTS = <<<'SQL'
        SELECT d.name,
               s.name         AS site_name,
               u.uuid::text   AS manager_user_uuid
          FROM departments d
          JOIN sites s  ON s.id = d.site_id
          LEFT JOIN users u ON u.id = d.manager_user_id
         ORDER BY d.id
        SQL;

    private const string EMPLOYEES = <<<'SQL'
        SELECT e.uuid::text          AS employee_uuid,
               e.employee_code::text AS employee_code,
               e.first_name,
               e.last_name,
               e.email::text         AS email,
               d.name                AS department_name,
               e.status,
               to_char(e.hired_at, 'YYYY-MM-DD')      AS hired_at,
               to_char(e.terminated_at, 'YYYY-MM-DD') AS terminated_at,
               e.locale,
               to_char(e.pin_issued_at    AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS pin_issued_at,
               to_char(e.pin_delivered_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS pin_delivered_at,
               pu.uuid::text         AS pin_delivered_by_user_uuid,
               to_char(e.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at,
               to_char(e.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at
          FROM employees e
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN users pu      ON pu.id = e.pin_delivered_by_user_id
         ORDER BY e.id
        SQL;

    private const string EMPLOYMENT_CONTRACTS = <<<'SQL'
        SELECT e.uuid::text  AS employee_uuid,
               c.weekly_hours::text AS weekly_hours,
               c.annual_hours::text AS annual_hours,
               c.schedule_type,
               to_char(c.valid_from, 'YYYY-MM-DD') AS valid_from,
               to_char(c.valid_to,   'YYYY-MM-DD') AS valid_to,
               to_char(c.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at,
               u.uuid::text  AS created_by_user_uuid
          FROM employment_contracts c
          JOIN employees e  ON e.id = c.employee_id
          LEFT JOIN users u ON u.id = c.created_by_user_id
         ORDER BY c.id
        SQL;

    private const string CREDENTIALS = <<<'SQL'
        SELECT cr.uuid::text  AS credential_uuid,
               e.uuid::text   AS employee_uuid,
               cr.key_id,
               to_char(cr.issued_at    AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS issued_at,
               to_char(cr.printed_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS printed_at,
               to_char(cr.delivered_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS delivered_at,
               du.uuid::text  AS delivered_by_user_uuid,
               to_char(cr.revoked_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS revoked_at,
               cr.revoked_reason
          FROM credentials cr
          JOIN employees e   ON e.id = cr.employee_id
          LEFT JOIN users du ON du.id = cr.delivered_by_user_id
         ORDER BY cr.id
        SQL;

    private const string DEVICES = <<<'SQL'
        SELECT d.uuid::text AS device_uuid,
               d.name,
               s.name       AS site_name,
               d.app_version,
               d.status,
               d.pending_queue_size::text AS pending_queue_size,
               to_char(d.paired_at    AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS paired_at,
               to_char(d.last_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS last_seen_at,
               to_char(d.created_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at,
               to_char(d.updated_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at
          FROM devices d
          JOIN sites s ON s.id = d.site_id
         ORDER BY d.id
        SQL;

    private const string SHIFT_ENTRIES = <<<'SQL'
        SELECT se.uuid::text  AS shift_entry_uuid,
               e.uuid::text   AS employee_uuid,
               s.name         AS site_name,
               to_char(se.work_date, 'YYYY-MM-DD') AS work_date,
               to_char(se.clocked_in_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS clocked_in_at,
               to_char(se.clocked_out_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS clocked_out_at,
               se.duration_minutes::text AS duration_minutes,
               se.status,
               se.clock_in_source,
               se.clock_out_source,
               se.version::text          AS version,
               sup.uuid::text            AS superseded_by_uuid,
               to_char(se.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at,
               to_char(se.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at
          FROM shift_entries se
          JOIN employees e ON e.id = se.employee_id
          JOIN sites s     ON s.id = se.site_id
          LEFT JOIN shift_entries sup ON sup.id = se.superseded_by_id
         ORDER BY se.id
        SQL;

    private const string SHIFT_CORRECTIONS = <<<'SQL'
        SELECT se.uuid::text AS shift_entry_uuid,
               c.action,
               u.uuid::text  AS performed_by_user_uuid,
               u.name        AS performed_by_name,
               c.reason_code,
               c.reason_text,
               c.before::text AS before,
               c.after::text  AS after,
               to_char(c.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at
          FROM shift_corrections c
          JOIN shift_entries se ON se.id = c.shift_entry_id
          JOIN users u          ON u.id = c.performed_by_user_id
         ORDER BY c.id
        SQL;

    private const string DAILY_TOTALS = <<<'SQL'
        SELECT e.uuid::text AS employee_uuid,
               to_char(dt.work_date, 'YYYY-MM-DD') AS work_date,
               dt.total_minutes::text AS total_minutes,
               dt.shift_count::text   AS shift_count,
               to_char(dt.first_in_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS first_in_at,
               to_char(dt.last_out_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS last_out_at,
               dt.has_open_shift::text AS has_open_shift,
               dt.has_incident::text   AS has_incident,
               to_char(dt.recalculated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS recalculated_at
          FROM daily_totals dt
          JOIN employees e ON e.id = dt.employee_id
         ORDER BY dt.id
        SQL;

    private const string INCIDENTS = <<<'SQL'
        SELECT e.uuid::text  AS employee_uuid,
               to_char(i.work_date, 'YYYY-MM-DD') AS work_date,
               se.uuid::text AS shift_entry_uuid,
               i.type,
               i.severity,
               i.status,
               to_char(i.detected_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS detected_at,
               i.context::text AS context,
               au.uuid::text   AS assigned_to_user_uuid,
               to_char(i.notified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS notified_at,
               to_char(i.resolved_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS resolved_at,
               ru.uuid::text   AS resolved_by_user_uuid,
               i.resolution_note,
               to_char(i.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at,
               to_char(i.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at
          FROM incidents i
          JOIN employees e            ON e.id = i.employee_id
          LEFT JOIN shift_entries se  ON se.id = i.shift_entry_id
          LEFT JOIN users au          ON au.id = i.assigned_to_user_id
          LEFT JOIN users ru          ON ru.id = i.resolved_by_user_id
         ORDER BY i.id
        SQL;

    private const string SCAN_EVENTS = <<<'SQL'
        SELECT sc.scan_id::text AS scan_id,
               d.uuid::text     AS device_uuid,
               e.uuid::text     AS employee_uuid,
               to_char(sc.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS occurred_at,
               to_char(sc.recorded_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS recorded_at,
               sc.origin,
               sc.intent,
               sc.result,
               se.uuid::text    AS shift_entry_uuid,
               sc.worked_minutes::text      AS worked_minutes,
               sc.clock_skew_seconds::text  AS clock_skew_seconds,
               sc.flagged_for_review::text  AS flagged_for_review,
               sc.client_meta::text         AS client_meta
          FROM scan_events sc
          JOIN devices d              ON d.id = sc.device_id
          LEFT JOIN employees e       ON e.id = sc.employee_id
          LEFT JOIN shift_entries se  ON se.id = sc.shift_entry_id
         ORDER BY sc.id
        SQL;

    /*
     * `audit_log` sale ENTERO y con los identificadores internos dentro. Ver el
     * docblock de la clase: el hash se calcula sobre `actor_type#actor_id` y
     * `subject_type#subject_id`, asi que sustituirlos por UUID entregaria un
     * fichero que no se puede verificar (RL-04). `actor_uuid` va ADEMAS, para
     * que el fichero se pueda leer sin adivinar quien es el usuario 4.
     */
    private const string AUDIT_LOG = <<<'SQL'
        SELECT a.id::text AS id,
               to_char(a.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS occurred_at,
               a.actor_type,
               a.actor_id::text AS actor_id,
               CASE a.actor_type
                   WHEN 'user'          THEN (SELECT u.uuid::text FROM users u          WHERE u.id = a.actor_id)
                   WHEN 'device'        THEN (SELECT d.uuid::text FROM devices d        WHERE d.id = a.actor_id)
                   WHEN 'support_grant' THEN (SELECT g.uuid::text FROM support_grants g WHERE g.id = a.actor_id)
                   ELSE NULL
               END AS actor_uuid,
               a.action,
               a.subject_type,
               a.subject_id::text AS subject_id,
               a.payload::text    AS payload,
               a.prev_hash,
               a.hash,
               a.ip::text         AS ip,
               a.user_agent
          FROM audit_log a
         ORDER BY a.id
        SQL;

    private const string AUDIT_CHAIN_ANCHORS = <<<'SQL'
        SELECT ac.partition_year::text AS partition_year,
               ac.first_hash,
               ac.last_hash,
               ac.row_count::text      AS row_count,
               to_char(ac.sealed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS sealed_at,
               ac.sealed_by
          FROM audit_chain_anchors ac
         ORDER BY ac.id
        SQL;

    /*
     * Los roles salen RESUELTOS y en una sola celda separados por espacio: es lo
     * que responde «¿que podia hacer esta cuenta?». Las tablas de union de la
     * libreria de permisos son un detalle de implementacion y no viajan.
     *
     * Del segundo factor sale si esta activo, nunca el secreto ni el contador
     * (regla dura 21 aplicada a lo que sale del producto).
     */
    private const string USERS = <<<'SQL'
        SELECT u.uuid::text AS user_uuid,
               u.name,
               u.email::text AS email,
               COALESCE((
                   SELECT string_agg(r.name, ' ' ORDER BY r.name)
                     FROM model_has_roles mhr
                     JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_id = u.id
                      AND mhr.model_type = 'App\Modules\Identity\Infrastructure\Persistence\User'
               ), '') AS roles,
               u.locale,
               u.is_active::text AS is_active,
               (u.two_factor_confirmed_at IS NOT NULL)::text AS two_factor_enabled,
               to_char(u.last_login_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS last_login_at,
               to_char(u.created_at    AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at,
               to_char(u.updated_at    AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at
          FROM users u
         ORDER BY u.id
        SQL;

    private const string SUPPORT_GRANTS = <<<'SQL'
        SELECT g.uuid::text  AS support_grant_uuid,
               gu.uuid::text AS granted_by_user_uuid,
               g.reason,
               g.scope,
               to_char(g.granted_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS granted_at,
               to_char(g.expires_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS expires_at,
               to_char(g.revoked_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS revoked_at,
               ru.uuid::text AS revoked_by_user_uuid,
               to_char(g.accessed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS accessed_at
          FROM support_grants g
          JOIN users gu      ON gu.id = g.granted_by_user_id
          LEFT JOIN users ru ON ru.id = g.revoked_by_user_id
         ORDER BY g.id
        SQL;

    /*
     * El historico de errores (RF-PD-15). `context` sale como texto JSON en una
     * celda del CSV, que es lo correcto para una hoja de calculo; el `README`
     * explica que hay dentro.
     */
    private const string ERROR_EVENTS = <<<'SQL'
        SELECT e.fingerprint,
               e.level,
               e.source,
               e.module,
               e.code,
               e.message,
               e.exception_class,
               e.file,
               e.line::text          AS line,
               e.context::text       AS context,
               e.trace_id,
               e.device_id::text     AS device_id,
               e.employee_uuid::text AS employee_uuid,
               e.app_version,
               e.occurrences::text   AS occurrences,
               to_char(e.first_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS first_seen_at,
               to_char(e.last_seen_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS last_seen_at,
               to_char(e.resolved_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS resolved_at,
               u.uuid::text          AS resolved_by_user_uuid
          FROM error_events e
          LEFT JOIN users u ON u.id = e.resolved_by_user_id
         ORDER BY e.id
        SQL;

    private const string INSTALLATION_SETTINGS = <<<'SQL'
        SELECT s.key,
               s.value::text AS value,
               to_char(s.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at,
               u.uuid::text  AS updated_by_user_uuid
          FROM installation_settings s
          LEFT JOIN users u ON u.id = s.updated_by_user_id
         ORDER BY s.key
        SQL;

    private const string COMPLIANCE_PROFILES = <<<'SQL'
        SELECT p.name,
               p.jurisdiction,
               p.retention_years::text            AS retention_years,
               p.min_rest_hours::text             AS min_rest_hours,
               p.max_daily_hours::text            AS max_daily_hours,
               p.max_weekly_hours::text           AS max_weekly_hours,
               p.break_required_after_hours::text AS break_required_after_hours,
               p.week_starts_on::text             AS week_starts_on,
               p.holiday_calendar::text           AS holiday_calendar,
               p.is_default::text                 AS is_default,
               to_char(p.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS updated_at,
               u.uuid::text                       AS updated_by_user_uuid
          FROM compliance_profiles p
          LEFT JOIN users u ON u.id = p.updated_by_user_id
         ORDER BY p.id
        SQL;

    /*
     * Sin `signed_key`: la clave firmada es del fabricante, el cliente ya la
     * tiene en su correo de activacion y publicarla aqui la pondria en un ZIP
     * que va a viajar. Lo que si sale es todo lo que la clave DICE, que es lo
     * que el cliente necesita para saber que contrato tenia.
     */
    private const string LICENSE = <<<'SQL'
        SELECT l.license_id,
               l.customer_name,
               l.plan,
               l.max_employees::text AS max_employees,
               l.max_devices::text   AS max_devices,
               l.features::text      AS features,
               to_char(l.valid_from       AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS valid_from,
               to_char(l.valid_until      AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS valid_until,
               to_char(l.issued_at        AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS issued_at,
               to_char(l.activated_at     AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS activated_at,
               u.uuid::text          AS activated_by_user_uuid,
               to_char(l.last_verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS last_verified_at
          FROM license l
          LEFT JOIN users u ON u.id = l.activated_by_user_id
         ORDER BY l.id
        SQL;
}
