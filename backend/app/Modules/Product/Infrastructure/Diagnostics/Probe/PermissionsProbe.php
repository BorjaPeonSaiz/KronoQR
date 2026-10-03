<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Domain\ValueObject\LogoRejection;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Infrastructure\Diagnostics\RuntimeService;
use Throwable;

/**
 * Sondas `permissions.*` de `product:doctor` (RF-PD-13).
 *
 * ## Los permisos son la averia clasica de una instalacion en casa del cliente
 *
 * Un `chown` mal puesto tras una restauracion, un directorio creado por `root`
 * durante una prueba, un volumen montado de solo lectura. El sintoma es siempre
 * el mismo —«a veces da error 500»— y la causa nunca esta donde se busca. Cuatro
 * comprobaciones de tres lineas ahorran la mitad de las llamadas de soporte.
 *
 * ## Las copias: lo que cada contenedor puede tocar (2.2.0, bloque 20, A3-R2)
 *
 * Hasta la 2.1.0 la sonda exigia que la raiz de `BACKUP_PATH` fuera escribible,
 * porque `app`, `horizon` y `scheduler` la montaban entera en escritura: quien
 * ejecutara codigo en cualquiera de ellos podia borrar copias o plantar una sin
 * la clave. Desde la 2.2.0 la raiz se monta en solo lectura y cada servicio
 * recibe en escritura solo lo suyo ({@see RuntimeService}). Las tres
 * comprobaciones dicen exactamente eso:
 *
 * - `permissions.backup_path` — la raiz existe y se puede leer (si no, **no hay
 *   copias**: `failure`) y NO se puede escribir. Escribible en produccion es el
 *   `docker-compose.yml` de la 2.1.0 todavia en uso: `warning`, porque no rompe
 *   nada hoy pero deja las copias al alcance de la aplicacion.
 * - `permissions.backup_metrics` — `metrics/` existe y se puede escribir. Sin
 *   eso no se publica ni el resultado de la copia ni el RPO, y las alertas de
 *   las copias se quedan ciegas: `failure`.
 * - `permissions.backup_copies` — `daily/` y `base/` existen (si no, no hay
 *   copias: `failure`). Desde `scheduler` se tienen que poder escribir; desde
 *   `app` y `horizon`, en produccion, NO (`warning` si se puede).
 *
 * Fuera de produccion no se avisa de lo escribible: el entorno de desarrollo
 * monta un volumen con nombre en escritura, y un aviso permanente en
 * desarrollo entrena a ignorar avisos.
 */
final readonly class PermissionsProbe implements DoctorProbe
{
    /**
     * @param  list<string>  $writablePaths  `storage/` y `bootstrap/cache`.
     * @param  string  $metricsPath  El directorio del colector textfile (`BACKUP_PATH/metrics`).
     */
    public function __construct(
        private array $writablePaths,
        private string $backupPath,
        private ?string $brandingLogoRoot,
        private GetSettingsHandler $settings,
        private LogoInspector $logos,
        private string $metricsPath = '',
        private string $environment = 'production',
        private RuntimeService $service = RuntimeService::App,
    ) {}

    public function family(): string
    {
        return 'permissions';
    }

    public function run(): array
    {
        return [
            $this->storage(),
            $this->backup(),
            $this->backupMetrics(),
            $this->backupCopies(),
            $this->brandingRoot(),
            $this->brandingLogo(),
        ];
    }

    private function storage(): DoctorFinding
    {
        $blocked = array_values(array_filter(
            $this->writablePaths,
            static fn (string $path): bool => ! is_dir($path) || ! is_writable($path),
        ));

        if ($blocked === []) {
            return DoctorFinding::ok('permissions.storage', ['paths' => $this->writablePaths]);
        }

        return DoctorFinding::failure(
            'permissions.storage',
            params: ['paths' => implode(', ', $blocked)],
            details: ['not_writable' => $blocked],
        );
    }

    private function backup(): DoctorFinding
    {
        if (! is_dir($this->backupPath)) {
            return DoctorFinding::failure(
                'permissions.backup_path',
                'missing',
                params: ['path' => $this->backupPath],
                details: ['path' => $this->backupPath],
            );
        }

        if (! is_readable($this->backupPath)) {
            return DoctorFinding::failure(
                'permissions.backup_path',
                'unreadable',
                params: ['path' => $this->backupPath],
                details: ['path' => $this->backupPath, 'readable' => false],
            );
        }

        $details = ['path' => $this->backupPath, 'service' => $this->service->value];

        if (is_writable($this->backupPath)) {
            if ($this->isProduction()) {
                return DoctorFinding::warning(
                    'permissions.backup_path',
                    'writable',
                    params: ['path' => $this->backupPath, 'service' => $this->service->value],
                    details: [...$details, 'writable' => true],
                );
            }

            return new DoctorFinding(
                'permissions.backup_path',
                DoctorStatus::Ok,
                ['path' => $this->backupPath],
                [...$details, 'writable' => true, 'app_env' => $this->environment],
                'not_checked',
            );
        }

        return DoctorFinding::ok('permissions.backup_path', [...$details, 'writable' => false], ['path' => $this->backupPath]);
    }

    private function backupMetrics(): DoctorFinding
    {
        $path = $this->metricsPath !== '' ? $this->metricsPath : rtrim($this->backupPath, '/').'/metrics';
        $params = ['path' => $path];

        if (! is_dir($path)) {
            return DoctorFinding::failure('permissions.backup_metrics', 'missing', $params, ['path' => $path]);
        }

        if (! is_writable($path)) {
            return DoctorFinding::failure('permissions.backup_metrics', params: $params, details: ['path' => $path]);
        }

        return DoctorFinding::ok('permissions.backup_metrics', ['path' => $path], $params);
    }

    /**
     * `daily/` (volcados) y `base/` (copias fisicas), que solo escribe el
     * planificador.
     */
    private function backupCopies(): DoctorFinding
    {
        $root = rtrim($this->backupPath, '/');
        $paths = [$root.'/daily', $root.'/base'];
        $service = $this->service->value;

        $missing = array_values(array_filter($paths, static fn (string $path): bool => ! is_dir($path)));

        if ($missing !== []) {
            return DoctorFinding::failure(
                'permissions.backup_copies',
                'missing',
                ['paths' => implode(', ', $missing)],
                ['missing' => $missing],
            );
        }

        $writable = array_values(array_filter($paths, static fn (string $path): bool => is_writable($path)));

        if ($this->service->writesBackups()) {
            $blocked = array_values(array_diff($paths, $writable));

            if ($blocked !== []) {
                return DoctorFinding::failure(
                    'permissions.backup_copies',
                    params: ['paths' => implode(', ', $blocked)],
                    details: ['not_writable' => $blocked, 'service' => $service],
                );
            }

            return DoctorFinding::ok('permissions.backup_copies', ['paths' => $paths, 'service' => $service]);
        }

        if ($writable !== [] && $this->isProduction()) {
            return DoctorFinding::warning(
                'permissions.backup_copies',
                'writable',
                ['paths' => implode(', ', $writable), 'service' => $service],
                ['writable' => $writable, 'service' => $service],
            );
        }

        return DoctorFinding::ok('permissions.backup_copies', ['paths' => $paths, 'service' => $service, 'writable' => $writable]);
    }

    private function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    private function brandingRoot(): DoctorFinding
    {
        if ($this->brandingLogoRoot === null || $this->brandingLogoRoot === '') {
            return DoctorFinding::ok('permissions.branding_root', ['configured' => false]);
        }

        if (! is_dir($this->brandingLogoRoot) || ! is_readable($this->brandingLogoRoot)) {
            // `warning` y no `failure`: sin logotipo se pinta la marca del
            // producto y todo lo demas funciona (tarea 5.8).
            return DoctorFinding::warning(
                'permissions.branding_root',
                params: ['path' => $this->brandingLogoRoot],
                details: ['path' => $this->brandingLogoRoot],
            );
        }

        return DoctorFinding::ok('permissions.branding_root', ['path' => $this->brandingLogoRoot]);
    }

    private function brandingLogo(): DoctorFinding
    {
        try {
            $path = $this->settings->handle()->text(SettingKey::BRANDING_LOGO_PATH);
        } catch (Throwable $failure) {
            return DoctorFinding::warning('permissions.branding_logo', 'unknown', details: ['error' => $failure::class]);
        }

        if ($path === '') {
            return DoctorFinding::ok('permissions.branding_logo', ['configured' => false]);
        }

        $inspection = $this->logos->inspect($path);

        if ($inspection->isAccepted()) {
            return DoctorFinding::ok('permissions.branding_logo', ['configured' => true]);
        }

        $rejection = $inspection->rejection();

        return DoctorFinding::warning(
            'permissions.branding_logo',
            self::logoVariant($rejection),
            // La RAZON del rechazo, no la ruta: la ruta la escribe el cliente y
            // puede llevar el nombre del hotel. El codigo exacto queda en los
            // detalles, para soporte; el texto para personas lo da la variante.
            details: ['reason' => $rejection->value],
        );
    }

    /**
     * Agrupa los nueve motivos en los tres «que hacer» distintos que existen.
     *
     * Antes el aviso imprimia el codigo en bruto —«(missing)»— y mandaba subir
     * el logotipo desde el panel, cosa que no se puede hacer (DC6). Cada grupo
     * lleva a una accion concreta: mover el fichero a la carpeta de marca,
     * comprobar que esta y se puede leer, o sustituirlo por uno admitido.
     */
    private static function logoVariant(LogoRejection $rejection): string
    {
        return match ($rejection) {
            LogoRejection::NOT_ABSOLUTE, LogoRejection::TRAVERSAL, LogoRejection::OUTSIDE_ROOT => 'path',
            LogoRejection::MISSING, LogoRejection::UNREADABLE => 'missing',
            LogoRejection::TOO_LARGE, LogoRejection::UNSUPPORTED_FORMAT, LogoRejection::ACTIVE_CONTENT,
            LogoRejection::TOO_MANY_PIXELS => 'content',
        };
    }
}
