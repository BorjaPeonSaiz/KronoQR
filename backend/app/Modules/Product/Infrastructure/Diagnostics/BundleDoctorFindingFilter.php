<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Application\Port\DoctorFindingFilter;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Lo que un hallazgo de `doctor` lleva **dentro del paquete de diagnostico**:
 * una lista cerrada de claves (**RL-19**, RF-PD-09; ADR-020, enmienda del
 * 01-11-2026).
 *
 * ## Cada clave de `params` y `details`, en una de cuatro clases
 *
 * - **Pasa tal cual**: cifras, estados, nombres de servicio, roles, clases de
 *   excepcion, identificadores del producto (`count`, `category`, `service`,
 *   `mailer`, `roles`, `exception`…).
 * - **Ubicacion** (`path`, `paths`, `root`, `roots`…): una ruta sale como la
 *   etiqueta de su papel (`<backup_path>`, `<backup_path>/daily`) o `[path]`
 *   ({@see DiagnosticsLocations}). Un booleano o una cifra con el mismo nombre
 *   —`writable: true`, `missing: 2`— pasan.
 * - **Etiqueta del producto** (`first`, `second`, `name`, `names`…): pasa si es
 *   una de las raices de clase que nombra el producto
 *   (`PRODUCT_DATA_EXPORT_PATH`); si no, se trata como ubicacion.
 * - **Host** (`host`): sale `[app_host]`. Es el nombre publico del hotel.
 *
 * **Una clave que no esta en ninguna se descarta**: falla cerrado. La sonda
 * nueva que añada una clave sin clasificarla pierde ese dato en el paquete, no
 * filtra una ruta; `DiagnosticsSealingTest` (Architecture) falla
 * mientras tanto, para que nadie lo descubra leyendo un paquete.
 *
 * El `product:doctor` local no pasa por aqui y sigue enseñando la ruta real.
 */
final readonly class BundleDoctorFindingFilter implements DoctorFindingFilter
{
    /** Lo que sale en lugar del nombre de `APP_URL`. */
    public const string APP_HOST = '[app_host]';

    public const string PASS = 'pass';

    public const string LOCATION = 'location';

    public const string LABEL = 'label';

    public const string HOST = 'host';

    /**
     * Las claves que pasan tal cual. Ordenadas para poder revisarlas.
     */
    public const array PASS_KEYS = [
        'age_seconds', 'ahead', 'allowed', 'app_env', 'app_url', 'app_version', 'applied_migrations',
        'behind', 'broadcast_connection', 'by_action', 'by_day', 'by_role',
        'can_delete', 'can_update', 'cap', 'category', 'ceiling', 'chain', 'checked_at', 'configured',
        'connection', 'count', 'current_rows',
        'daily_at', 'database_user', 'days', 'days_since_expiry', 'days_until_expiry', 'debug', 'delayed', 'device',
        'destination_offsite', 'devices', 'differing', 'dormant_accounts',
        'enabled', 'enforced', 'error', 'error_code', 'examined', 'exceeded_limits', 'exception', 'excess',
        'expected', 'expected_recipients', 'expires_at',
        'failed', 'failure', 'family', 'free', 'free_bytes', 'free_percent',
        'guard_issues', 'guards_expected',
        'hours',
        'invalid', 'invalid_keys', 'issues',
        'key_id', 'keys',
        'last_entry_at', 'last_hash_present', 'last_result', 'last_run_at', 'last_verified_at',
        'last_verify_result', 'latency_ms', 'length',
        'mailer', 'maintenance', 'masters', 'max_age_hours', 'minimum', 'minimum_app_version', 'minutes',
        'missing_roles', 'mounted',
        'observability_profile', 'oldest', 'oldest_generated_at', 'open', 'open_by_source', 'overdue',
        'partition_year', 'pending', 'pending_accounts', 'pending_migrations', 'pending_queue_size', 'percent',
        'pin_length', 'port',
        'portal_exposed',
        'queue_storage',
        'readable', 'reachable', 'ready', 'reason', 'recipients_without_destination', 'reserved', 'resolvable',
        'restriction', 'roles', 'rows',
        'scheme', 'sealed_rows', 'sealed_years', 'self_signed', 'server_version', 'service', 'short_pins',
        'size', 'source', 'stale', 'state', 'status', 'supervisors',
        'timezone', 'tokens_without_expiry', 'total', 'total_bytes', 'total_groups',
        'unknown_keys', 'user',
        'verified_at',
        'waiting', 'wal_offsite', 'window_days',
        'year',
    ];

    /** Las que llevan una ruta, una lista de rutas o un mapa de rutas. */
    public const array LOCATION_KEYS = ['missing', 'not_writable', 'path', 'paths', 'root', 'roots', 'writable'];

    /** Las que llevan el nombre de una raiz de clase del producto. */
    public const array LABEL_KEYS = ['directories', 'first', 'name', 'names', 'outside_volume', 'second'];

    /** Las que llevan el nombre de `APP_URL`. */
    public const array HOST_KEYS = ['host'];

    /** Como `PermissionsProbe` y `GeneratedFilesProbe` unen varias rutas en un texto. */
    private const string JOINER = ', ';

    /**
     * @param  list<string>  $productLabels  Los nombres de las raices de clase
     *                                       (`GeneratedFileAreas::configuredRoots()`).
     */
    public function __construct(
        private DiagnosticsLocations $locations,
        private array $productLabels,
    ) {}

    /** La clase de una clave, o nula si no esta clasificada (se descarta). */
    public static function classify(string $key): ?string
    {
        return match (true) {
            \in_array($key, self::PASS_KEYS, true) => self::PASS,
            \in_array($key, self::LOCATION_KEYS, true) => self::LOCATION,
            \in_array($key, self::LABEL_KEYS, true) => self::LABEL,
            \in_array($key, self::HOST_KEYS, true) => self::HOST,
            default => null,
        };
    }

    public function filter(DoctorFinding $finding): DoctorFinding
    {
        /** @var array<string, string|int|float|bool|null> $params */
        $params = $this->entries($finding->params);

        return new DoctorFinding(
            $finding->id,
            $finding->status,
            $params,
            $this->entries($finding->details),
            $finding->variant,
        );
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, mixed>
     */
    private function entries(array $entries): array
    {
        $kept = [];

        foreach ($entries as $key => $value) {
            $class = self::classify((string) $key);

            if ($class === null) {
                continue;
            }

            $kept[(string) $key] = match ($class) {
                self::PASS => $value,
                self::LOCATION => $this->map($value, false),
                self::LABEL => $this->map($value, true),
                default => \is_string($value) ? self::APP_HOST : $value,
            };
        }

        return $kept;
    }

    /**
     * Una ruta, una lista de rutas, un mapa de rutas o rutas unidas por `, `.
     * Lo que no es texto —`writable: true`, `missing: 2`— pasa.
     */
    private function map(mixed $value, bool $labels): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->map($item, $labels), $value);
        }

        if (! \is_string($value)) {
            return $value;
        }

        return implode(self::JOINER, array_map(
            fn (string $part): string => $labels && \in_array($part, $this->productLabels, true)
                ? $part
                : $this->locations->label($part),
            explode(self::JOINER, $value),
        ));
    }
}
