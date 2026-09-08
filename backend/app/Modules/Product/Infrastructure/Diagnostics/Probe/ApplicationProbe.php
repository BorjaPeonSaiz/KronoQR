<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Sondas `app.*` de `product:doctor` (RF-PD-13).
 *
 * ## Dos comprobaciones, y las dos son `failure`
 *
 * **`app.timezone_utc`** (regla dura 3). Si el proceso no vive en UTC, cada
 * instante que se escriba a partir de ese momento queda desplazado, y el
 * desplazamiento **no se puede deshacer**: no queda registro de cual era la zona
 * del proceso cuando se escribio cada fila. Un registro horario con horas
 * desplazadas es un registro horario invalido, y eso es una infraccion, no una
 * molestia. Es la comprobacion que justifica que el instalador ejecute `doctor`.
 *
 * **`app.debug_in_production`** (RS-08, OWASP A05). Con `APP_DEBUG=true`, un
 * error devuelve la traza completa **con los valores del entorno dentro**: la
 * contraseña de la base de datos, `APP_KEY` y la clave de firma de los QR. En un
 * producto instalado en la red del cliente, eso es la fuga completa en una sola
 * peticion.
 */
final readonly class ApplicationProbe implements DoctorProbe
{
    public function __construct(
        private string $timezone,
        private bool $debug,
        private string $environment,
    ) {}

    public function family(): string
    {
        return 'app';
    }

    public function run(): array
    {
        return [$this->timezone(), $this->debug()];
    }

    private function timezone(): DoctorFinding
    {
        if ($this->timezone === 'UTC') {
            return DoctorFinding::ok('app.timezone_utc', ['timezone' => 'UTC']);
        }

        return DoctorFinding::failure(
            'app.timezone_utc',
            params: ['timezone' => $this->timezone],
            details: ['timezone' => $this->timezone],
        );
    }

    private function debug(): DoctorFinding
    {
        if (! $this->debug) {
            return DoctorFinding::ok('app.debug_in_production', ['app_env' => $this->environment, 'debug' => false]);
        }

        if ($this->environment !== 'production') {
            // Fuera de produccion es lo normal y no se dice nada: un aviso
            // permanente en desarrollo es ruido que entrena a ignorar avisos.
            return DoctorFinding::ok('app.debug_in_production', ['app_env' => $this->environment, 'debug' => true]);
        }

        return DoctorFinding::failure(
            'app.debug_in_production',
            details: ['app_env' => $this->environment, 'debug' => true],
        );
    }
}
