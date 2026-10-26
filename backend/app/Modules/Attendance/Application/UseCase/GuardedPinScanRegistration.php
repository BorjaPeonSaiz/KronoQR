<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterPinScanCommand;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * **Registrar un fichaje por PIN pasando por el guardian del descarte** (RN-22,
 * ADR-061): la puerta de `POST /api/v1/scan/pin`.
 *
 * El tramo de PIN de la cola offline sale siempre por esa ruta, de uno en uno:
 * sin esto, un PIN que el servidor falla siempre atascaria la cola entera
 * (RN-21) aunque el lote ya no lo hiciera.
 *
 * Envuelve el caso de uso **entero**, verificacion del PIN incluida: el veneno
 * puede estar en abrir el sobre o en comprobar el PIN tanto como en el registro.
 * Un reenvio de un fichaje ya descartado no llega a comprobar el PIN: lo
 * resuelve antes {@see RegisterScanHandler::replayOf()}.
 */
final readonly class GuardedPinScanRegistration
{
    public function __construct(
        private RegisterPinScanHandler $pins,
        private UnprocessableScanGuard $guard,
    ) {}

    public function handle(RegisterPinScanCommand $command): RegisterScanResult
    {
        return UseCaseInstrumentation::run('attendance.register_guarded_pin_scan', fn (): RegisterScanResult => $this->guard->register(
            GuardedScan::ofPinScan($command),
            fn (): RegisterScanResult => $this->pins->handle($command),
        ));
    }
}
