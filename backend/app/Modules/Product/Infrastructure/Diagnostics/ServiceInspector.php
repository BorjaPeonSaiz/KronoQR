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
