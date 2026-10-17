<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
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
    public const string SITE_TIMEZONE_GUARD_TRIGGER = 'sites_guard_timezone';

    public function __construct(
        private ConnectionInterface $database,
        private Redis $redis,
        private string $migrationsPath,
        private string $queueConnection,
        private string $queueName,
        private bool $realtimeEnabled,
        private string $broadcastConnection,
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
        return [
            'enabled' => $this->realtimeEnabled,
            'broadcast_connection' => $this->broadcastConnection,
            'configured' => $this->broadcastConnection !== 'null' && $this->broadcastConnection !== 'log',
        ];
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
     * - que `work_record_purge_batch` siga siendo `SECURITY DEFINER` y con su
     *   `search_path` fijado: sin lo primero no puede borrar, sin lo segundo una
     *   tabla temporal con el mismo nombre la desviaria;
     * - que la aplicacion no tenga `DELETE` sobre las siete tablas.
     *
     * Solo lee el catalogo; ninguna fila del registro.
     *
     * @return array{excess: list<string>, issues: list<string>}
     */
    private function workRecordGuards(): array
    {
        /** @var object{missing: string, disabled: string, definer: bool|null, search_path: bool|null, deletable: string} $row */
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
                (SELECT p.prosecdef FROM pg_catalog.pg_proc p
                  WHERE p.oid = to_regprocedure('public.work_record_purge_batch(date, integer)')) AS definer,
                (SELECT coalesce(bool_or(setting LIKE 'search_path=%'), false)
                   FROM pg_catalog.pg_proc p, unnest(p.proconfig) AS setting
                  WHERE p.oid = to_regprocedure('public.work_record_purge_batch(date, integer)')) AS search_path,
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

        if ($row->definer === null) {
            $issues[] = 'purge_function:missing';
        } else {
            if ($row->definer !== true) {
                $issues[] = 'purge_function:not_security_definer';
            }

            if ($row->search_path !== true) {
                $issues[] = 'purge_function:no_search_path';
            }
        }

        return [
            'excess' => array_values(array_filter(explode(',', $row->deletable))),
            'issues' => $issues,
        ];
    }

    /**
     * Las guardas de la zona del centro (RN-23, ADR-056, migracion
     * `2026_10_18_120000`), mirando lo mismo que `update.sh` y `doctor.sh`:
     *
     * - que exista {@see self::SITE_TIMEZONE_GUARD_TRIGGER} y que **ningun**
     *   *trigger* no interno de `sites` este desactivado;
     * - que `site_correct_timezone` siga siendo `SECURITY DEFINER` y con su
     *   `search_path`: sin lo primero, la correccion de consola chocaria con el
     *   *trigger*; sin lo segundo, una tabla temporal la desviaria.
     *
     * Solo lee el catalogo.
     *
     * @return list<string>
     */
    private function siteTimezoneGuards(): array
    {
        /** @var object{missing: bool, disabled: string, definer: bool|null, search_path: bool|null} $row */
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
                ), '') AS disabled,
                (SELECT p.prosecdef FROM pg_catalog.pg_proc p
                  WHERE p.oid = to_regprocedure('public.site_correct_timezone(bigint, text, text)')) AS definer,
                (SELECT coalesce(bool_or(setting LIKE 'search_path=%'), false)
                   FROM pg_catalog.pg_proc p, unnest(p.proconfig) AS setting
                  WHERE p.oid = to_regprocedure('public.site_correct_timezone(bigint, text, text)')) AS search_path
            SQL,
            [self::SITE_TIMEZONE_GUARD_TRIGGER],
        );

        $issues = $row->missing ? ['missing_trigger:'.self::SITE_TIMEZONE_GUARD_TRIGGER] : [];

        foreach (array_filter(explode(',', $row->disabled)) as $trigger) {
            $issues[] = 'disabled_trigger:'.$trigger;
        }

        if ($row->definer === null) {
            $issues[] = 'site_timezone_function:missing';
        } else {
            if ($row->definer !== true) {
                $issues[] = 'site_timezone_function:not_security_definer';
            }

            if ($row->search_path !== true) {
                $issues[] = 'site_timezone_function:no_search_path';
            }
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
