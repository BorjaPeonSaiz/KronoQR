<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use App\Support\Network\BoundedReachability;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use stdClass;
use Throwable;

/**
 * Lo que se puede saber de los servicios de los que depende el producto, sin
 * lanzar nunca (RF-PD-09, RF-PD-13).
 *
 * ## Una sola clase para el paquete y para `doctor`
 *
 * La seccion `services` del paquete y las sondas `database.*` y `queue.*` de
 * `product:doctor` preguntan lo mismo. Si cada una lo midiera por su cuenta, el
 * informe que el cliente ve en su terminal y el que llega a soporte dentro del
 * paquete podrian decir cosas distintas del mismo servicio, y esa conversacion
 * no lleva a ningun sitio.
 *
 * ## Ningun metodo lanza y ninguno devuelve un mensaje de excepcion
 *
 * Devuelven la **clase** de lo que fallo. Un `PDOException` lleva el DSN en el
 * mensaje y el DSN lleva la contraseña de la base de datos; ese texto acabaria
 * en un fichero que el cliente envia por correo al fabricante (regla dura 21,
 * ADR-020). La clase basta para saber si fue red, credenciales o sintaxis.
 */
final readonly class ServiceInspector
{
    /** Trabajos en cola a partir de los cuales conviene mirar (doc 02 §9.3). */
    public const int QUEUE_BACKLOG_WARNING = 500;

    /** Trabajos en cola que ya son un problema: el latido del quiosco se retrasa. */
    public const int QUEUE_BACKLOG_FAILURE = 5000;

    /**
     * Las migraciones que retiran los privilegios que vigila
     * {@see self::workRecordPrivileges()}, las mismas que mira `update.sh`.
     *
     * @var list<string>
     */
    public const array WORK_RECORD_PRIVILEGE_MIGRATIONS = [
        '2026_10_13_100000_restrict_application_updates_on_work_record',
        '2026_10_13_100100_restrict_application_writes_on_compliance_profiles',
    ];

    /**
     * La migracion que pone las guardas del registro (ADR-057 §2 y §3):
     * *triggers*, funcion de purga y sin `DELETE`. Sin ella aplicada no hay
     * guardas que vigilar.
     */
    public const string WORK_RECORD_GUARD_MIGRATION = '2026_10_14_100000_work_record_trigger_and_purge_function';

    /**
     * Los *triggers* de esa migracion, los mismos que mira
     * `infra/scripts/lib/work-record-privileges.sh`.
     *
     * @var list<string>
     */
    public const array WORK_RECORD_GUARD_TRIGGERS = [
        'incidents_guard_insert',
        'incidents_guard_transition',
        'shift_entries_guard_insert',
        'shift_entries_guard_transition',
        'shift_entries_superseded_is_linked',
    ];

    /**
     * Las tablas sin `DELETE` para la aplicacion desde esa migracion: la purga
     * pasa por la funcion.
     *
     * @var list<string>
     */
    public const array WORK_RECORD_NO_DELETE = [
        'daily_totals',
        'discarded_scan_reports',
        'incidents',
        'scan_events',
        'shift_corrections',
        'shift_entries',
        'sites',
    ];

    /**
     * La migracion que fija la zona del centro tambien en la base de datos
     * (RN-23, ADR-056, bloque 2 de la 2.3.0): el *trigger* de `sites` y la
     * funcion de la correccion de consola. Sin ella aplicada no se vigilan.
     */
    public const string SITE_TIMEZONE_GUARD_MIGRATION = '2026_10_18_120000_guard_site_timezone_in_database';

    /** El *trigger* de esa migracion, el mismo que mira `infra/scripts/lib/work-record-privileges.sh`. */
    public const string SITE_TIMEZONE_GUARD_TRIGGER = 'zz_sites_guard_timezone';

    /** La traza solo-append de los cambios de zona por la funcion de la correccion; la aplicacion solo la lee. */
    public const string SITE_TIMEZONE_TRACE = 'site_timezone_changes';

    /** La linea base de la conciliacion de la zona; la aplicacion solo la lee. */
    public const string SITE_TIMEZONE_BASELINE = 'site_timezone_audit_baseline';

    public function __construct(
        private ConnectionInterface $database,
        private Redis $redis,
        private string $migrationsPath,
        private string $queueConnection,
        private string $queueName,
        private bool $realtimeEnabled,
        private string $broadcastConnection,
        /**
         * Donde escucha Reverb para el servidor (`broadcasting.connections.reverb.options`).
         * Solo para conectar: ni el host ni el puerto salen en el paquete.
         */
        private ?string $realtimeHost = null,
        private ?int $realtimePort = null,
        private float $realtimeTimeoutSeconds = 1.0,
        private BoundedReachability $reachability = new BoundedReachability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function database(): array
    {
        try {
            /** @var object{version: string} $version */
            $version = $this->database->selectOne('SELECT version() AS version');

            $applied = array_values($this->database->table('migrations')->pluck('migration')->all());

            return [
                'reachable' => true,
                'server_version' => (string) $version->version,
                'applied_migrations' => \count($applied),
                'pending_migrations' => $this->pendingMigrations($applied),
            ];
        } catch (Throwable $failure) {
            return ['reachable' => false, 'error' => $failure::class];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function redis(): array
    {
        try {
            $startedAt = microtime(true);
            $this->redis->connection()->command('PING', []);

            return [
                'reachable' => true,
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        } catch (Throwable $failure) {
            return ['reachable' => false, 'error' => $failure::class];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(): array
    {
        $queue = ['connection' => $this->queueConnection, 'name' => $this->queueName];

        if ($this->queueConnection !== 'redis') {
            // Con `sync` o `database` el tamaño no se mide igual, y medirlo mal
            // seria peor que no medirlo: un cero inventado haria descartar la
            // hipotesis correcta.
            return [...$queue, 'size' => null, 'status' => 'unavailable', 'reason' => 'unsupported_driver'];
        }

        try {
            $connection = $this->redis->connection();

            $pending = self::count($connection->command('LLEN', ['queues:'.$this->queueName]));
            $delayed = self::count($connection->command('ZCARD', ['queues:'.$this->queueName.':delayed']));
            $reserved = self::count($connection->command('ZCARD', ['queues:'.$this->queueName.':reserved']));

            return [
                ...$queue,
                'size' => $pending + $delayed + $reserved,
                'pending' => $pending,
                'delayed' => $delayed,
                'reserved' => $reserved,
                'failed' => $this->failedJobs(),
            ];
        } catch (Throwable $failure) {
            return [...$queue, 'size' => null, 'status' => 'unavailable', 'reason' => $failure::class];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function realtime(): array
    {
        $configured = $this->broadcastConnection !== 'null' && $this->broadcastConnection !== 'log';

        return [
            'enabled' => $this->realtimeEnabled,
            'broadcast_connection' => $this->broadcastConnection,
            'configured' => $configured,
            ...$this->realtimeReachability($configured),
        ];
    }

    /**
     * Si Reverb responde (V4-PL-2): sin esto, «la presencia en vivo no se
     * actualiza» no distinguia un Reverb parado de una licencia caducada sin
     * pedir una segunda ronda.
     *
     * Una conexion TCP con un segundo de techo, nombre incluido, y nada mas
     * ({@see BoundedReachability}): no se habla el
     * protocolo de Pusher, que necesitaria el secreto de la aplicacion, y un
     * paquete que se genera con la base caida no puede quedarse esperando.
     *
     * **Anonimizado como el resto (ADR-020):** ni el host ni el puerto, que
     * describen la red del cliente; solo si responde, cuanto tarda y, si no, la
     * clase del fallo. `reachable: null` cuando no hay a quien preguntar: tiempo
     * real apagado o con un driver que no es Reverb.
     *
     * @return array{reachable: bool|null, latency_ms?: float, error?: string}
     */
    private function realtimeReachability(bool $configured): array
    {
        if (! $this->realtimeEnabled || ! $configured || $this->broadcastConnection !== 'reverb'
            || $this->realtimeHost === null || $this->realtimeHost === '' || $this->realtimePort === null) {
            return ['reachable' => null];
        }

        $startedAt = microtime(true);

        try {
            // La misma sonda acotada que el cortacircuitos de PostgreSQL y la
            // salud: con techo tambien para la resolucion del nombre, que es lo
            // que se queda colgado cuando el contenedor no existe.
            if (! $this->reachability->reachable($this->realtimeHost, $this->realtimePort, $this->realtimeTimeoutSeconds)) {
                return ['reachable' => false, 'error' => 'unreachable'];
            }

            return ['reachable' => true, 'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2)];
        } catch (Throwable $failure) {
            return ['reachable' => false, 'error' => $failure::class];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function auditLog(): array
    {
        try {
            /** @var object{total: int|string, last_entry_at: string|null} $summary */
            $summary = $this->database->selectOne(
                'SELECT COUNT(*) AS total, MAX(occurred_at)::text AS last_entry_at FROM audit_log'
            );

            return [
                'reachable' => true,
                'rows' => (int) $summary->total,
                'last_entry_at' => UtcInstant::fromDatabase($summary->last_entry_at),
                'sealed_years' => $this->database->table('audit_chain_anchors')->count(),
            ];
        } catch (Throwable $failure) {
            return ['reachable' => false, 'error' => $failure::class];
        }
    }

    /**
     * Si el usuario de la aplicacion **no** tiene `UPDATE` ni `DELETE` sobre
     * `audit_log` (regla dura 6, RL-04).
     *
     * Es la comprobacion mas importante de `doctor` y la mas facil de perder:
     * una restauracion hecha con el usuario equivocado, un `GRANT ALL` puesto
     * para salir del paso. Si esos permisos existen, la cadena de hash deja de
     * ser una garantia legal y nadie se entera hasta que una inspeccion lo
     * pregunta.
     *
     * @return array<string, mixed>
     */
    public function auditLogPrivileges(): array
    {
        try {
            /** @var object{can_update: bool, can_delete: bool, grantee: string} $privileges */
            $privileges = $this->database->selectOne(
                "SELECT has_table_privilege(current_user, 'audit_log', 'UPDATE') AS can_update, "
                ."has_table_privilege(current_user, 'audit_log', 'DELETE') AS can_delete, "
                .'current_user AS grantee'
            );

            return [
                'reachable' => true,
                'can_update' => (bool) $privileges->can_update,
                'can_delete' => (bool) $privileges->can_delete,
                // El NOMBRE del rol de base de datos, que es del despliegue y no
                // del cliente: sin el, «alguien tiene UPDATE» no dice a quien
                // quitarselo.
                'database_user' => (string) $privileges->grantee,
            ];
        } catch (Throwable $failure) {
            return ['reachable' => false, 'error' => $failure::class];
        }
    }

    /**
     * Lo que el usuario de la aplicacion tiene **de mas** sobre el registro
     * horario y sobre la asignacion del perfil de cumplimiento (ADR-057 §1 y
     * §7; RL-04, RS-07).
     *
     * Las migraciones `2026_10_13_*` retiran esos privilegios y `update.sh` los
     * comprueba y repara al actualizar, pero entre una actualizacion y la
     * siguiente nada los vigilaba: volver a ejecutar la provision (concede todo
     * con `GRANT … ON ALL TABLES`) o un `GRANT` a mano los devolvia en silencio.
     *
     * **Solo se mira lo que sobra**, no el conjunto exacto de `update.sh`: lo que
     * faltara rompe el fichaje y lo ve cualquiera, lo que sobra no lo ve nadie.
     * `has_table_privilege` no cuenta los privilegios de columna, que en
     * `shift_entries` e `incidents` son los legitimos; `has_column_privilege`
     * cuenta los de tabla y los de columna, que es lo que se quiere para las
     * tres columnas que deciden el suelo legal.
     *
     * Con las migraciones sin aplicar devuelve `expected = false`: esos
     * privilegios son los que la version anterior del esquema necesita, y la
     * sonda `database.migrations_pending` ya dice lo que pasa.
     *
     * @return array<string, mixed>
     */
    public function workRecordPrivileges(): array
    {
        try {
            $applied = $this->database->table('migrations')
                ->whereIn('migration', self::WORK_RECORD_PRIVILEGE_MIGRATIONS)
                ->count();

            if ($applied !== \count(self::WORK_RECORD_PRIVILEGE_MIGRATIONS)) {
                return ['reachable' => true, 'expected' => false];
            }

            /** @var object{grantee: string, excess: string} $privileges */
            $privileges = $this->database->selectOne(
                <<<'SQL'
                SELECT current_user AS grantee, coalesce(string_agg(d.x, ',' ORDER BY d.x), '') AS excess
                  FROM (
                    SELECT t.tbl || ':UPDATE' AS x
                      FROM unnest(ARRAY['shift_entries', 'shift_corrections', 'scan_events',
                                        'discarded_scan_reports', 'incidents']) AS t(tbl)
                     WHERE has_table_privilege(current_user, t.tbl, 'UPDATE')
                    UNION ALL
                    SELECT c.tbl || '.' || c.col || ':' || p.priv
                      FROM (VALUES ('compliance_profiles', 'jurisdiction'), ('compliance_profiles', 'is_default'),
                                   ('sites', 'compliance_profile_id')) AS c(tbl, col)
                     CROSS JOIN unnest(ARRAY['INSERT', 'UPDATE']) AS p(priv)
                     WHERE has_column_privilege(current_user, c.tbl, c.col, p.priv)
                  ) AS d
                SQL
            );

            $excess = $privileges->excess === '' ? [] : explode(',', $privileges->excess);

            $guarded = $this->database->table('migrations')
                ->where('migration', self::WORK_RECORD_GUARD_MIGRATION)
                ->exists();

            $guards = $guarded ? $this->workRecordGuards() : ['excess' => [], 'issues' => []];

            if ($guarded && $this->database->table('migrations')->where('migration', self::SITE_TIMEZONE_GUARD_MIGRATION)->exists()) {
                $guards['issues'] = [...$guards['issues'], ...$this->siteTimezoneGuards()];
            }

            $excess = [...$excess, ...$guards['excess']];
            sort($excess);

            return [
                'reachable' => true,
                'expected' => true,
                'excess' => $excess,
                'guards_expected' => $guarded,
                'guard_issues' => $guards['issues'],
                // El NOMBRE del rol, como en `auditLogPrivileges`: sin el, el
                // aviso no dice a quien quitarselos.
                'database_user' => (string) $privileges->grantee,
            ];
        } catch (Throwable $failure) {
            return ['reachable' => false, 'error' => $failure::class];
        }
    }

    /**
     * Las guardas del registro (ADR-057 §2 y §3), mirando lo mismo que `update.sh` y `doctor.sh`
     * (`infra/scripts/lib/work-record-privileges.sh`):
     *
     * - que existan los cinco *triggers* de {@see self::WORK_RECORD_GUARD_TRIGGERS};
     * - que **ningun** *trigger* no interno de `shift_entries` o `incidents`
     *   este desactivado (`tgenabled <> 'O'`): `ALTER TABLE … DISABLE TRIGGER`
     *   con el rol de migracion los apaga sin borrarlos, y un *trigger* que se
     *   añada mañana queda cubierto sin tocar esta lista. Los internos son los de
     *   las claves ajenas;
     * - que `work_record_purge_batch` siga como debe
     *   ({@see self::definerFunctionIssues()});
     * - que la aplicacion no tenga `DELETE` sobre las siete tablas.
     *
     * Solo lee el catalogo; ninguna fila del registro.
     *
     * @return array{excess: list<string>, issues: list<string>}
     */
    private function workRecordGuards(): array
    {
        /** @var object{missing: string, disabled: string, deletable: string} $row */
        $row = $this->database->selectOne(
            <<<'SQL'
            SELECT
                coalesce((
                    SELECT string_agg(expected.name, ',' ORDER BY expected.name)
                      FROM unnest(?::text[]) AS expected(name)
                     WHERE NOT EXISTS (
                         SELECT 1 FROM pg_catalog.pg_trigger g
                          WHERE g.tgname = expected.name
                            AND NOT g.tgisinternal
                            AND g.tgrelid IN ('public.shift_entries'::regclass, 'public.incidents'::regclass)
                     )
                ), '') AS missing,
                coalesce((
                    SELECT string_agg(g.tgname, ',' ORDER BY g.tgname)
                      FROM pg_catalog.pg_trigger g
                     WHERE g.tgrelid IN ('public.shift_entries'::regclass, 'public.incidents'::regclass)
                       AND NOT g.tgisinternal
                       AND g.tgenabled <> 'O'
                ), '') AS disabled,
                coalesce((
                    SELECT string_agg(t.tbl || ':DELETE', ',' ORDER BY t.tbl)
                      FROM unnest(?::text[]) AS t(tbl)
                     WHERE has_table_privilege(current_user, t.tbl, 'DELETE')
                ), '') AS deletable
            SQL,
            [
                '{'.implode(',', self::WORK_RECORD_GUARD_TRIGGERS).'}',
                '{'.implode(',', self::WORK_RECORD_NO_DELETE).'}',
            ],
        );

        $issues = [];

        foreach (array_filter(explode(',', $row->missing)) as $trigger) {
            $issues[] = 'missing_trigger:'.$trigger;
        }

        foreach (array_filter(explode(',', $row->disabled)) as $trigger) {
            $issues[] = 'disabled_trigger:'.$trigger;
        }

        return [
            'excess' => array_values(array_filter(explode(',', $row->deletable))),
            'issues' => [...$issues, ...$this->definerFunctionIssues('public.work_record_purge_batch(date, integer)', 'purge_function')],
        ];
    }

    /**
     * Las guardas de la zona del centro (RN-23, ADR-056, migracion
     * `2026_10_18_120000`), mirando lo mismo que `update.sh` y `doctor.sh`:
     *
     * - que exista {@see self::SITE_TIMEZONE_GUARD_TRIGGER} y que **ningun**
     *   *trigger* no interno de `sites` este desactivado;
     * - que `site_correct_timezone` siga como debe
     *   ({@see self::definerFunctionIssues()}): sin `SECURITY DEFINER`, la
     *   correccion de consola chocaria con el *trigger* y no podria escribir la
     *   traza;
     * - que la traza {@see self::SITE_TIMEZONE_TRACE} y la linea base
     *   {@see self::SITE_TIMEZONE_BASELINE} existan, sean del mismo propietario
     *   que `sites` y la aplicacion solo las pueda leer
     *   ({@see self::readOnlyTableIssues()}).
     *
     * Solo lee el catalogo.
     *
     * @return list<string>
     */
    private function siteTimezoneGuards(): array
    {
        /** @var object{missing: bool, disabled: string} $row */
        $row = $this->database->selectOne(
            <<<'SQL'
            SELECT
                NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_trigger g
                     WHERE g.tgrelid = 'public.sites'::regclass AND g.tgname = ? AND NOT g.tgisinternal
                ) AS missing,
                coalesce((
                    SELECT string_agg(g.tgname, ',' ORDER BY g.tgname)
                      FROM pg_catalog.pg_trigger g
                     WHERE g.tgrelid = 'public.sites'::regclass AND NOT g.tgisinternal AND g.tgenabled <> 'O'
                ), '') AS disabled
            SQL,
            [self::SITE_TIMEZONE_GUARD_TRIGGER],
        );

        $issues = $row->missing ? ['missing_trigger:'.self::SITE_TIMEZONE_GUARD_TRIGGER] : [];

        foreach (array_filter(explode(',', $row->disabled)) as $trigger) {
            $issues[] = 'disabled_trigger:'.$trigger;
        }

        return [
            ...$issues,
            ...$this->definerFunctionIssues('public.site_correct_timezone(bigint, text, text)', 'site_timezone_function'),
            ...$this->readOnlyTableIssues(self::SITE_TIMEZONE_TRACE, 'site_timezone_trace', self::SITE_TIMEZONE_TRACE.'_id_seq'),
            ...$this->readOnlyTableIssues(self::SITE_TIMEZONE_BASELINE, 'site_timezone_baseline', null),
        ];
    }

    /**
     * Lo que le falta a una tabla que la aplicacion solo debe leer, con el
     * prefijo `$label`:
     *
     * - `missing`: no existe;
     * - `owner`: no es del propietario de `sites` (el rol de migracion);
     * - `owner_membership`: quien pregunta no es el propietario pero es miembro
     *   de su rol: con herencia tiene todos sus privilegios, y sin ella
     *   (`NOINHERIT`, como la aplicacion) le basta un `SET ROLE` para ser el
     *   propietario. En produccion la sonda corre con la aplicacion: que sea
     *   miembro del propietario es justo lo que no puede pasar;
     * - `<PRIVILEGIO>`: un privilegio de escritura sobre la tabla;
     * - `sequence`: `USAGE` sobre su secuencia.
     *
     * Si quien pregunta **es** el propietario no se miran los privilegios: los
     * tiene todos por serlo.
     *
     * @return list<string>
     */
    private function readOnlyTableIssues(string $table, string $label, ?string $sequence): array
    {
        $qualified = 'public.'.$table;

        /** @var object{present: bool, owner: bool, is_owner: bool, member: bool, writable: string, sequence: bool} $row */
        $row = $this->database->selectOne(
            <<<'SQL'
            SELECT c.oid IS NOT NULL AS present,
                   coalesce(c.relowner = (SELECT s.relowner FROM pg_catalog.pg_class s WHERE s.oid = 'public.sites'::regclass), true) AS owner,
                   coalesce(c.relowner = (SELECT r.oid FROM pg_catalog.pg_roles r WHERE r.rolname = current_user), false) AS is_owner,
                   coalesce(pg_has_role(current_user, c.relowner, 'MEMBER'), false) AS member,
                   coalesce((
                       SELECT string_agg(p.priv, ',' ORDER BY p.priv)
                         FROM unnest(ARRAY['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN']) AS p(priv)
                        WHERE has_table_privilege(current_user, c.oid, p.priv)
                   ), '') AS writable,
                   coalesce(has_sequence_privilege(current_user, to_regclass(?), 'USAGE'), false) AS sequence
              FROM (SELECT to_regclass(?) AS oid) AS wanted
              LEFT JOIN pg_catalog.pg_class c ON c.oid = wanted.oid
            SQL,
            [$sequence === null ? null : 'public.'.$sequence, $qualified],
        );

        if (! $row->present) {
            return [$label.':missing'];
        }

        $issues = $row->owner ? [] : [$label.':owner'];

        if ($row->is_owner) {
            return $issues;
        }

        if ($row->member) {
            return [...$issues, $label.':owner_membership'];
        }

        foreach (array_filter(explode(',', $row->writable)) as $privilege) {
            $issues[] = $label.':'.$privilege;
        }

        if ($row->sequence) {
            $issues[] = $label.':sequence';
        }

        return $issues;
    }

    /**
     * Lo que le falta a una funcion `SECURITY DEFINER` del registro para ser
     * lo que su migracion dejo, con el prefijo `$label`:
     *
     * - `missing`: no existe;
     * - `not_security_definer`: sin el, no puede hacer lo que la aplicacion no
     *   puede;
     * - `no_search_path`: sin `search_path` fijado, una tabla temporal con el
     *   mismo nombre la desviaria;
     * - `public_execute`: `PUBLIC` la puede ejecutar (`aclexplode`, `grantee = 0`),
     *   y con ella cualquier rol de la base, no solo la aplicacion.
     *
     * @return list<string>
     */
    private function definerFunctionIssues(string $signature, string $label): array
    {
        /** @var object{definer: bool|null, search_path: bool|null, public_execute: bool|null} $row */
        $row = $this->database->selectOne(
            <<<'SQL'
            SELECT p.prosecdef AS definer,
                   coalesce((SELECT bool_or(setting LIKE 'search_path=%') FROM unnest(p.proconfig) AS setting), false) AS search_path,
                   EXISTS (
                       SELECT 1 FROM aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) AS a
                        WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE'
                   ) AS public_execute
              FROM (SELECT to_regprocedure(?) AS oid) AS wanted
              LEFT JOIN pg_catalog.pg_proc p ON p.oid = wanted.oid
            SQL,
            [$signature],
        );

        if ($row->definer === null) {
            return [$label.':missing'];
        }

        $issues = [];

        if ($row->definer !== true) {
            $issues[] = $label.':not_security_definer';
        }

        if ($row->search_path !== true) {
            $issues[] = $label.':no_search_path';
        }

        if ($row->public_execute === true) {
            $issues[] = $label.':public_execute';
        }

        return $issues;
    }

    /**
     * Verifica el **ultimo ancla** de la cadena de auditoria.
     *
     * No recorre la cadena entera: hacerlo son millones de filas y `doctor` se
     * ejecuta durante una instalacion. La verificacion completa es
     * `php artisan compliance:verify-audit-chain`, y a eso apunta el `fix`
     * cuando esto sale mal.
     *
     * @return array<string, mixed>
     */
    public function auditChainAnchor(): array
    {
        try {
            /** @var object{partition_year: int|string, last_hash: string, row_count: int|string}|null $anchor */
            $anchor = $this->database->table('audit_chain_anchors')
                ->orderByDesc('partition_year')
                ->first();

            if ($anchor === null) {
                return ['status' => 'no_anchor'];
            }

            $year = (int) $anchor->partition_year;

            $rows = $this->database->table('audit_log')
                ->whereBetween('occurred_at', [$year.'-01-01 00:00:00+00', ($year + 1).'-01-01 00:00:00+00'])
                ->count();

            $present = $this->database->table('audit_log')
                ->where('hash', (string) $anchor->last_hash)
                ->exists();

            return [
                'status' => $present && $rows === (int) $anchor->row_count ? 'verified' : 'mismatch',
                'partition_year' => $year,
                'sealed_rows' => (int) $anchor->row_count,
                'current_rows' => $rows,
                'last_hash_present' => $present,
            ];
        } catch (Throwable $failure) {
            return ['status' => 'unavailable', 'reason' => $failure::class];
        }
    }

    /**
     * El instante del ultimo asiento, para la sonda que avisa de una cadena
     * parada.
     */
    public function lastAuditEntryAt(): ?DateTimeImmutable
    {
        try {
            /** @var object{last_entry_at: string|null}|null $row */
            $row = $this->database->selectOne('SELECT MAX(occurred_at)::text AS last_entry_at FROM audit_log');

            $value = $row?->last_entry_at;

            return $value === null ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recuentos de `audit_log` por accion y por dia de los ultimos `$days`
     * dias, **sin un solo payload** (seccion `audit` del paquete).
     *
     * @return array<string, mixed>
     */
    public function auditTally(int $days, DateTimeImmutable $now): array
    {
        $since = $now->modify('-'.$days.' days');

        try {
            $byAction = $this->database->table('audit_log')
                ->where('occurred_at', '>=', $since->format(DateTimeInterface::ATOM))
                ->selectRaw('action, COUNT(*) AS total')
                ->groupBy('action')
                ->orderBy('action')
                ->get();

            $byDay = $this->database->table('audit_log')
                ->where('occurred_at', '>=', $since->format(DateTimeInterface::ATOM))
                ->selectRaw("to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day, COUNT(*) AS total")
                ->groupBy('day')
                ->orderBy('day')
                ->get();

            return [
                'window_days' => $days,
                'by_action' => $this->tally($byAction, 'action'),
                'by_day' => $this->tally($byDay, 'day'),
                'chain' => $this->auditChainAnchor(),
            ];
        } catch (Throwable $failure) {
            return ['status' => 'unavailable', 'reason' => $failure::class];
        }
    }

    /**
     * Migraciones que estan en el codigo y no en la tabla.
     *
     * **Se devuelven los nombres, y son nombres de fichero del producto**, no
     * del cliente: `2026_09_08_100000_create_license_table`. Sin ellos, un
     * «faltan 3 migraciones» obliga a preguntar cuales, y esa segunda ronda de
     * preguntas es lo que ADR-020 existe para evitar.
     *
     * @param  list<mixed>  $applied
     * @return list<string>
     */
    private function pendingMigrations(array $applied): array
    {
        $done = array_map(
            static fn (mixed $migration): string => is_scalar($migration) ? (string) $migration : '',
            $applied,
        );

        $files = glob(rtrim($this->migrationsPath, '/').'/*.php');

        if ($files === false) {
            return [];
        }

        $pending = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (! in_array($name, $done, true)) {
                $pending[] = $name;
            }
        }

        sort($pending, SORT_STRING);

        return $pending;
    }

    /**
     * Un contador de Redis convertido a entero **sin dar por hecho su tipo**.
     *
     * `command()` devuelve `mixed`: el cliente phpredis responde un entero y
     * predis un objeto de estado. Un `(int)` a secas daria `0` en el segundo
     * caso, y una cola de ocho mil trabajos apareceria como vacia — que es
     * exactamente el diagnostico contrario al correcto.
     */
    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function failedJobs(): ?int
    {
        try {
            return $this->database->table('failed_jobs')->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, int>
     */
    private function tally(Collection $rows, string $label): array
    {
        $tally = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $key = $values[$label] ?? null;
            $total = $values['total'] ?? 0;

            if (is_string($key) && is_numeric($total)) {
                $tally[$key] = (int) $total;
            }
        }

        return $tally;
    }
}
