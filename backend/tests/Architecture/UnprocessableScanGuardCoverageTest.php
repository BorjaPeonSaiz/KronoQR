<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\UseCase\GuardedPinScanRegistration;
use App\Modules\Attendance\Application\UseCase\GuardedScanRegistration;
use App\Modules\Attendance\Application\UseCase\RegisterScanBatchHandler;
use App\Modules\Attendance\Application\UseCase\ScanRegistration;
use App\Modules\Attendance\Http\Controller\PinScanController;
use App\Modules\Attendance\Http\Controller\ScanBatchController;
use App\Modules\Attendance\Http\Controller\ScanController;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **Las tres rutas de escritura del fichaje pasan por el guardian del descarte**
 * (RN-22, ADR-061 §3: «un solo servicio por el que las tres tienen que pasar»).
 *
 * Sin esta prueba, un controlador nuevo —o uno que vuelva a pedir el caso de uso
 * concreto por comodidad— se saltaria el descarte y su ruta volveria a atascar
 * la cola para siempre, sin que ninguna prueba de comportamiento lo notara: el
 * camino feliz es identico con y sin guardian.
 *
 * Tres comprobaciones:
 *
 * 1. `/scan` y `/scan/batch` piden el contrato `ScanRegistration`, y el
 *    proveedor lo enlaza a `GuardedScanRegistration`; `/scan/pin` pide
 *    `GuardedPinScanRegistration`.
 * 2. Nadie mas en `app/Modules` depende de los dos casos de uso concretos,
 *    salvo los dos guardianes y el PIN, que delega en el de la tarjeta.
 * 3. Los dos guardianes pasan por `UnprocessableScanGuard`.
 */

/**
 * @return list<string> los tipos de los parametros de un metodo, por nombre de clase
 */
function tiposDeParametros(string $clase, string $metodo): array
{
    $tipos = [];

    foreach ((new ReflectionMethod($clase, $metodo))->getParameters() as $parametro) {
        $tipo = $parametro->getType();

        if ($tipo instanceof ReflectionNamedType) {
            $tipos[] = $tipo->getName();
        }
    }

    return $tipos;
}

it('hace que /scan y el lote pidan el contrato y /scan/pin la puerta con guardian', function (): void {
    expect(tiposDeParametros(ScanController::class, '__invoke'))->toContain(ScanRegistration::class)
        ->and(tiposDeParametros(PinScanController::class, '__invoke'))->toContain(GuardedPinScanRegistration::class)
        ->and(tiposDeParametros(ScanBatchController::class, '__invoke'))->toContain(RegisterScanBatchHandler::class)
        ->and(tiposDeParametros(RegisterScanBatchHandler::class, '__construct'))->toContain(ScanRegistration::class);
})->group('RN-22', 'RN-21');

it('enlaza el contrato del registro a la puerta con guardian', function (): void {
    $proveedor = Repo::contents('backend/app/Modules/Attendance/AttendanceServiceProvider.php');

    expect($proveedor)->toContain('bind(ScanRegistration::class, GuardedScanRegistration::class)')
        ->and($proveedor)->not->toContain('bind(ScanRegistration::class, RegisterScanHandler::class)');
})->group('RN-22');

it('no deja que nadie mas dependa de los casos de uso del fichaje sin guardian', function (): void {
    $permitidos = [
        'Attendance/Application/UseCase/GuardedScanRegistration.php',
        'Attendance/Application/UseCase/GuardedPinScanRegistration.php',
        // El PIN delega en el de la tarjeta: el guardian lo envuelve entero.
        'Attendance/Application/UseCase/RegisterPinScanHandler.php',
        'Attendance/Application/UseCase/RegisterScanHandler.php',
    ];

    $ficheros = ModuleTree::filesIn('');
    $intrusos = [];

    // Sin esta guarda la prueba no podria fallar sobre el bind mount, que a
    // veces no ve ficheros (ver el docblock de `ModuleTree`).
    expect(\count($ficheros))->toBeGreaterThan(100);

    foreach ($ficheros as $fichero) {
        $relativo = ModuleTree::relative($fichero);

        if (\in_array($relativo, $permitidos, true)) {
            continue;
        }

        $codigo = (string) file_get_contents($fichero);

        if (preg_match('/\b(RegisterScanHandler|RegisterPinScanHandler)\s+\$/', $codigo) === 1) {
            $intrusos[] = $relativo;
        }
    }

    expect($intrusos)->toBe([], 'Estos ficheros piden el caso de uso del fichaje sin pasar por UnprocessableScanGuard (ADR-061): '.implode(', ', $intrusos));
})->group('RN-22');

it('hace pasar las dos puertas por el guardian', function (): void {
    foreach ([GuardedScanRegistration::class, GuardedPinScanRegistration::class] as $puerta) {
        $codigo = (string) file_get_contents((string) (new ReflectionClass($puerta))->getFileName());

        expect($codigo)->toContain('private UnprocessableScanGuard $guard')
            ->and($codigo)->toContain('$this->guard->register(');
    }
})->group('RN-22');
