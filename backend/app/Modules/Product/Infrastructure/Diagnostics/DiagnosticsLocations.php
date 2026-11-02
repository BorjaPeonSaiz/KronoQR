<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Domain\Support\ErrorTextAllowlist;
use App\Modules\Shared\Domain\Support\PathOverlap;
use Illuminate\Support\Facades\Config;

/**
 * Las ubicaciones de la instalacion **por su papel en el producto**, para que
 * el paquete de diagnostico hable de ellas sin decir donde estan (**RL-19**,
 * RF-PD-09; ADR-020, enmienda del 01-11-2026).
 *
 * ## Por que una ruta no sale del servidor
 *
 * `BACKUP_PATH=/srv/hotel-ejemplo-sol/copias` dice a quien recibe el paquete el
 * nombre del cliente y como esta montado su servidor, y a soporte no le aporta
 * nada que no diga `<backup_path>`: lo que necesita saber es **que papel**
 * tiene la ubicacion que falla, si es la de serie, si existe y si se puede
 * escribir. Eso es lo que sale: una etiqueta y, en `configuration.locations`,
 * `customized`, `exists` y `writable`.
 *
 * ## Una lista cerrada de diez papeles
 *
 * Cada uno con su valor configurado y su valor de serie. Una ruta que es la de
 * un papel sale como `<papel>`; una que cuelga de una, como `<papel>/` y el
 * resto pasado por la lista blanca de palabras de ADR-048 (`daily`, `reports`
 * sobreviven; el nombre de un hotel, no); cualquier otra, como `[path]`. Se
 * compara el valor tal cual y su `realpath`, y gana la raiz mas larga: el
 * fichero de metricas cae en `<backup_metrics>` y no en `<backup_path>/metrics`.
 *
 * El `product:doctor` local no pasa por aqui: quien administra el servidor
 * necesita la ruta real.
 */
final readonly class DiagnosticsLocations
{
    /** Lo que sale en lugar de una ruta que no cuelga de ningun papel. */
    public const string UNKNOWN = '[path]';

    /** Los papeles, en el orden en que salen en `configuration.locations`. */
    public const array ROLES = [
        'backup_path',
        'backup_metrics',
        'storage_root',
        'application_root',
        'diagnostics',
        'data_export',
        'console_exports',
        'retention_reports',
        'technical_log',
        'branding_logo_root',
    ];

    /**
     * El `BACKUP_PATH` de serie (`config/backup.php`). Lo comprueba
     * `DiagnosticsPathsAndSealingTest`.
     */
    public const string SERIES_BACKUP_PATH = '/var/backups/fichaje';

    /** Donde vive la aplicacion en la imagen del producto. */
    public const string SERIES_APPLICATION_ROOT = '/var/www/html';

    /** Una ruta absoluta dentro de un texto libre. */
    private const string ABSOLUTE_PATH = '#(?<![\w.~-])/[^\s()<>"\'`,;·]+#u';

    /**
     * @param  array<string, string>  $roots  Papel => ruta configurada.
     * @param  array<string, string>  $seriesDefaults  Papel => ruta de serie.
     */
    public function __construct(
        private array $roots,
        private array $seriesDefaults,
    ) {}

    /**
     * Los diez papeles con lo que dice la configuracion de esta instalacion.
     * Lectura tolerante: el paquete se genera cuando algo va mal, y una clave
     * rara no puede tumbarlo.
     */
    public static function fromConfiguration(): self
    {
        $backup = self::text(Config::get('backup.path')) ?? self::SERIES_BACKUP_PATH;
        $backupRoot = rtrim($backup, '/');

        return new self(
            roots: [
                'backup_path' => $backup,
                'backup_metrics' => self::text(Config::get('observability.metrics.textfile_path')) ?? $backupRoot.'/metrics',
                'storage_root' => storage_path(),
                'application_root' => base_path(),
                'diagnostics' => self::text(Config::get('product.diagnostics_storage_path')) ?? storage_path('app/diagnostics'),
                'data_export' => self::text(Config::get('product.data_export_path')) ?? storage_path('app/exports'),
                'console_exports' => self::text(Config::get('compliance.legal_export_console_path')) ?? storage_path('app/legal-exports'),
                'retention_reports' => self::text(Config::get('compliance.retention.report_path')) ?? $backupRoot.'/reports/retention',
                'technical_log' => self::text(Config::get('compliance.retention.technical_log_path')) ?? storage_path('logs'),
                'branding_logo_root' => self::text(Config::get('branding.logo_root')) ?? storage_path('app/branding'),
            ],
            // Los de serie, relativos a las raices de las que cuelgan: unas
            // metricas en `<BACKUP_PATH>/metrics` siguen siendo las de serie
            // aunque `BACKUP_PATH` no lo sea.
            seriesDefaults: [
                'backup_path' => self::SERIES_BACKUP_PATH,
                'backup_metrics' => $backupRoot.'/metrics',
                'storage_root' => base_path('storage'),
                'application_root' => self::SERIES_APPLICATION_ROOT,
                'diagnostics' => storage_path('app/diagnostics'),
                'data_export' => storage_path('app/exports'),
                'console_exports' => storage_path('app/legal-exports'),
                'retention_reports' => $backupRoot.'/reports/retention',
                'technical_log' => storage_path('logs'),
                'branding_logo_root' => storage_path('app/branding'),
            ],
        );
    }

    /**
     * La etiqueta de una ruta: `<papel>`, `<papel>/resto` o `[path]`.
     */
    public function label(string $path): string
    {
        $candidates = self::forms(trim($path));

        if ($candidates === []) {
            return self::UNKNOWN;
        }

        foreach ($this->rootsByLength() as [$role, $root]) {
            foreach ($candidates as $candidate) {
                if ($candidate === $root) {
                    return '<'.$role.'>';
                }

                if (str_starts_with($candidate, $root.'/')) {
                    return '<'.$role.'>/'.ErrorTextAllowlist::apply(substr($candidate, \strlen($root) + 1));
                }
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Un texto libre con cada ruta absoluta cambiada por su etiqueta. Para las
     * lineas del informe de actualizacion, que nombran el directorio de la
     * instalacion y la copia previa.
     */
    public function redact(string $text): string
    {
        return preg_replace_callback(
            self::ABSOLUTE_PATH,
            fn (array $match): string => $this->label($match[0]),
            $text,
        ) ?? '';
    }

    /**
     * `configuration.locations`: por papel, si es distinta de la de serie, si
     * existe y si este proceso puede escribir en ella. Nunca el valor.
     *
     * @return array<string, array{customized: bool, exists: bool, writable: bool}>
     */
    public function describe(): array
    {
        $described = [];

        foreach (self::ROLES as $role) {
            $path = $this->roots[$role] ?? '';
            $exists = $path !== '' && is_dir($path);

            $described[$role] = [
                'customized' => PathOverlap::normalise($path) !== PathOverlap::normalise($this->seriesDefaults[$role] ?? ''),
                'exists' => $exists,
                'writable' => $exists && is_writable($path),
            ];
        }

        return $described;
    }

    /**
     * Las raices normalizadas, tal cual y resueltas, de la mas larga a la mas
     * corta: gana la mas especifica. Nunca `/` ni una vacia, que lo
     * contendrian todo.
     *
     * @return list<array{string, string}>
     */
    private function rootsByLength(): array
    {
        $roots = [];

        foreach (self::ROLES as $role) {
            foreach (self::forms($this->roots[$role] ?? '') as $form) {
                if ($form !== '/') {
                    $roots[] = [$role, $form];
                }
            }
        }

        usort($roots, static fn (array $a, array $b): int => \strlen($b[1]) <=> \strlen($a[1]));

        return $roots;
    }

    /**
     * La ruta normalizada y, si existe, la resuelta. Una ruta relativa no es
     * una ubicacion del servidor: no tiene formas.
     *
     * @return list<string>
     */
    private static function forms(string $path): array
    {
        if ($path === '' || ! str_starts_with(str_replace('\\', '/', $path), '/')) {
            return [];
        }

        $forms = [PathOverlap::normalise($path)];
        $real = @realpath($path);

        if (\is_string($real)) {
            $forms[] = PathOverlap::normalise($real);
        }

        return array_values(array_unique($forms));
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? $value : null;
    }
}
