<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * **Registrar un escaneo de tarjeta pasando por el guardian del descarte**
 * (RN-22, ADR-061): la puerta de `POST /api/v1/scan` y de cada elemento de
 * `POST /api/v1/scan/batch`.
 *
 * Es lo que el contenedor sirve como {@see ScanRegistration}: el lote y el
 * escaneo suelto dependen del contrato y reciben esto, asi que ninguno de los
 * dos puede llegar a {@see RegisterScanHandler} sin pasar por
 * {@see UnprocessableScanGuard}. Lo vigila `UnprocessableScanGuardCoverageTest`.
 *
 * No añade ninguna regla: en el camino feliz devuelve lo que devuelve el caso
 * de uso, sin una consulta mas.
 */
final readonly class GuardedScanRegistration implements ScanRegistration
{
    public function __construct(
        private RegisterScanHandler $scans,
        private UnprocessableScanGuard $guard,
    ) {}

    public function handle(RegisterScanCommand $command): RegisterScanResult
    {
        return UseCaseInstrumentation::run('attendance.register_guarded_scan', fn (): RegisterScanResult => $this->guard->register(
            GuardedScan::ofScan($command),
            fn (): RegisterScanResult => $this->scans->handle($command),
        ));
    }
}
