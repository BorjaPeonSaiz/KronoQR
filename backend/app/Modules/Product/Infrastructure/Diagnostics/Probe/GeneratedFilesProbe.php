<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\PathOverlap;
use App\Modules\Shared\Infrastructure\GeneratedFiles\GeneratedFileAreas;

/**
 * Sondas `files.*` de `product:doctor`: donde viven los ficheros que genera el
 * producto (RF-PD-13; ADR-045, condicion C3).
 *
 * ## Por que hace falta
 *
 * Porque el fallo que cierra ADR-045 no dio ningun sintoma en las pruebas ni en
 * desarrollo: `app`, `horizon` y `scheduler` tenian cada uno su `storage/app`,
 * la exportacion del panel respondia `404` y las purgas no veian los ficheros
 * con datos personales. Estas cuatro comprobaciones son lo que lo habria
 * detectado en la primera instalacion:
 *
 * - `files.storage_volume` — `storage/app` es un punto de montaje (dispositivo
 *   distinto del de la aplicacion) y se puede escribir. Fuera de produccion no
 *   se exige el montaje: el entorno de desarrollo monta el codigo entero.
 * - `files.retention_reports` — el directorio de los informes de retencion se
 *   puede escribir, y no esta dentro de `storage/app`.
 * - `files.class_roots` — las raices de clase no coinciden ni se solapan, ni son
 *   `storage/app`, ni estan dentro de `BACKUP_PATH`. **Falla**: con dos raices
 *   pisandose, una purga veria lo que no es suyo.
 * - `files.legal_exports_console` — exportaciones legales de consola con mas de
 *   30 dias en el servidor. **Avisa y nunca falla**: es custodia humana y nadie
 *   las borra por su cuenta.
 *
 * Las rutas salen en el informe, como en `permissions.*` y `disk.*`: lo lee quien
 * administra el servidor, y sin la ruta no sabria que arreglar.
 */
final readonly class GeneratedFilesProbe implements DoctorProbe
{
    /**
     * @param  array<string, string>  $classRoots  Por nombre legible (`GeneratedFileAreas::configuredRoots()`).
     */
    public function __construct(
        private string $storageAppPath,
        private string $applicationPath,
        private string $environment,
        private string $retentionReportPath,
        private string $backupPath,
        private array $classRoots,
        private GeneratedFileArea $consoleExports,
        private int $consoleWarningDays,
        private GeneratedFileHousekeeping $files,
        private Clock $clock,
        private GeneratedFileStore $store,
    ) {}

    public function family(): string
    {
        return 'files';
    }

    public function run(): array
    {
        return [
            $this->storageVolume(),
            $this->retentionReports(),
            $this->classRoots(),
            $this->strayEntries(),
            $this->consoleExports(),
        ];
    }

    /**
     * Ficheros con nombre de una clase generada en un directorio de primer nivel
     * de `storage/app` que NO es ninguna raiz configurada.
     *
     * Es lo que deja cambiar `PRODUCT_DATA_EXPORT_PATH` o `REPORTING_EXPORT_PATH`:
     * la purga solo mira la raiz nueva, y la anterior se queda con ZIP e informes
     * que ya nadie va a borrar. Aviso y nunca fallo: no rompe nada, pero es un
     * plazo de retencion que no se esta cumpliendo.
     */
    private function strayEntries(): DoctorFinding
    {
        $roots = array_map($this->resolved(...), $this->classRoots);
        $stray = [];

        foreach (glob(rtrim($this->storageAppPath, '/').'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || \in_array($this->resolved($directory), $roots, true)) {
                continue;
            }

            if ($this->holdsGeneratedFiles($directory)) {
                $stray[] = basename($directory);
            }
        }

        if ($stray === []) {
            // Detalles no vacios: el contrato del diagnostico exige un objeto, y
            // un array PHP vacio se serializa como lista.
            return DoctorFinding::ok('files.stray_entries', ['directories' => []]);
        }

        return DoctorFinding::warning(
            'files.stray_entries',
            params: ['names' => implode(', ', $stray)],
            details: ['directories' => $stray],
        );
    }

    private function holdsGeneratedFiles(string $directory): bool
    {
        foreach ([
            GeneratedFileAreas::dataExportArchives($directory),
            GeneratedFileAreas::dataExportArchiveTemporaries($directory),
            GeneratedFileAreas::dataExportWorkspaces($directory),
            GeneratedFileAreas::reportExports($directory),
            GeneratedFileAreas::legalExportTemporaries($directory),
            GeneratedFileAreas::diagnostics($directory),
        ] as $area) {
            if ($this->store->entries($area) !== []) {
                return true;
            }
        }

        return false;
    }

    private function storageVolume(): DoctorFinding
    {
        $path = $this->storageAppPath;

        if (! is_dir($path) || ! is_writable($path)) {
            return DoctorFinding::failure('files.storage_volume', params: ['path' => $path], details: ['path' => $path]);
        }

        $mounted = self::deviceOf($path) !== self::deviceOf($this->applicationPath);

        if ($mounted) {
            return DoctorFinding::ok('files.storage_volume', ['path' => $path, 'mounted' => true]);
        }

        if ($this->environment !== 'production') {
            // Fuera de produccion es lo normal: `compose.dev.yaml` monta el codigo
            // entero y `storage/app` cae en el mismo dispositivo. Un aviso
            // permanente en desarrollo entrena a ignorar avisos.
            return new DoctorFinding(
                'files.storage_volume',
                DoctorStatus::Ok,
                details: ['path' => $path, 'mounted' => false, 'app_env' => $this->environment],
                variant: 'not_checked',
            );
        }

        return DoctorFinding::failure(
            'files.storage_volume',
            'not_mounted',
            params: ['path' => $path],
            details: ['path' => $path, 'mounted' => false],
        );
    }

    private function retentionReports(): DoctorFinding
    {
        $path = $this->retentionReportPath;
        $params = ['path' => $path];

        if (PathOverlap::contains($this->resolved($this->storageAppPath), $this->resolved($path))) {
            return DoctorFinding::warning('files.retention_reports', 'inside_storage', $params, ['path' => $path]);
        }

        if (! is_dir($path)) {
            return DoctorFinding::warning('files.retention_reports', 'missing', $params, ['path' => $path]);
        }

        if (! is_writable($path)) {
            return DoctorFinding::warning('files.retention_reports', params: $params, details: ['path' => $path]);
        }

        // `ok()` recibe los DETALLES primero y los parametros del texto despues:
        // sin el tercer argumento el informe imprimia «:path» literal.
        return DoctorFinding::ok('files.retention_reports', ['path' => $path], ['path' => $path]);
    }

    private function classRoots(): DoctorFinding
    {
        $storage = $this->resolved($this->storageAppPath);
        $backup = $this->resolved($this->backupPath);
        $roots = array_map($this->resolved(...), $this->classRoots);
        $labels = array_keys($roots);

        foreach ($roots as $label => $root) {
            if (PathOverlap::contains($root, $storage)) {
                return $this->rootFailure('storage_root', ['name' => $label, 'path' => $root]);
            }

            if (PathOverlap::between($root, $backup)) {
                return $this->rootFailure('backup_path', ['name' => $label, 'path' => $root]);
            }
        }

        foreach ($labels as $index => $first) {
            foreach (\array_slice($labels, $index + 1) as $second) {
                if (PathOverlap::between($roots[$first], $roots[$second])) {
                    return $this->rootFailure('overlap', ['first' => $first, 'second' => $second]);
                }
            }
        }

        $outside = array_keys(array_filter(
            $roots,
            static fn (string $root): bool => ! PathOverlap::contains($storage, $root),
        ));

        if ($outside !== []) {
            // En produccion es FALLO, igual que `files.storage_volume`: una raiz
            // fuera del volumen compartido es R3-PL-01 otra vez —lo que escribe
            // `horizon` no lo ve `app`—. Fuera de produccion, aviso.
            return new DoctorFinding(
                'files.class_roots',
                $this->environment === 'production' ? DoctorStatus::Failure : DoctorStatus::Warning,
                ['names' => implode(', ', $outside)],
                ['outside_volume' => $outside],
                'outside_volume',
            );
        }

        return DoctorFinding::ok('files.class_roots', ['roots' => $roots]);
    }

    private function consoleExports(): DoctorFinding
    {
        $days = max(1, $this->consoleWarningDays);
        $overdue = $this->files->countOlderThan($this->consoleExports, $days * 86400, $this->clock->now());
        $params = ['count' => $overdue, 'days' => $days, 'path' => $this->consoleExports->root];

        if ($overdue === 0) {
            return DoctorFinding::ok('files.legal_exports_console', ['overdue' => 0, 'days' => $days], $params);
        }

        return DoctorFinding::warning('files.legal_exports_console', params: $params, details: ['overdue' => $overdue]);
    }

    /** @param  array<string, string>  $params */
    private function rootFailure(string $variant, array $params): DoctorFinding
    {
        return DoctorFinding::failure('files.class_roots', $variant, $params, $params);
    }

    /** La ruta resuelta si existe; si no, normalizada como texto. */
    private function resolved(string $path): string
    {
        $real = realpath($path);

        return PathOverlap::normalise($real === false ? $path : $real);
    }

    private static function deviceOf(string $path): ?int
    {
        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'];
    }
}
