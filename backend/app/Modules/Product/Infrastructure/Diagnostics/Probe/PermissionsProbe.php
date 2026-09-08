<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\SettingKey;
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
 * ## `permissions.backup_path` es la que no se puede fallar
 *
 * Si el directorio de copias no es escribible, **no hay copias**, y no hay
 * ningun otro sintoma hasta el dia que hagan falta. Va como `failure`.
 */
final readonly class PermissionsProbe implements DoctorProbe
{
    /**
     * @param  list<string>  $writablePaths  `storage/` y `bootstrap/cache`.
     */
    public function __construct(
        private array $writablePaths,
        private string $backupPath,
        private ?string $brandingLogoRoot,
        private GetSettingsHandler $settings,
        private LogoInspector $logos,
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

        if (! is_writable($this->backupPath)) {
            return DoctorFinding::failure(
                'permissions.backup_path',
                params: ['path' => $this->backupPath],
                details: ['path' => $this->backupPath],
            );
        }

        return DoctorFinding::ok('permissions.backup_path', ['path' => $this->backupPath]);
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

        return DoctorFinding::warning(
            'permissions.branding_logo',
            params: ['reason' => $inspection->rejection()->value],
            // La RAZON del rechazo, no la ruta: la ruta la escribe el cliente y
            // puede llevar el nombre del hotel.
            details: ['reason' => $inspection->rejection()->value],
        );
    }
}
