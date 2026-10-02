<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\ConcurrentEmployeeWrite;
use App\Modules\Workforce\Domain\Exception\EmployeeNationalIdAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\WorkforceConflict;

/*
 * Los dos `409` nuevos de la escritura de la plantilla (ADR-046 §1.3, bloque 17):
 * el documento ya registrado, que antes salia como `500`, y el cruce de dos
 * escrituras que sobrevive al reintento. Los dos son conflictos —se traducen a
 * `409` por la raiz comun— y ninguno lleva el dato en el mensaje (RL-08, regla
 * dura 21).
 */

it('son conflictos y explican que hacer sin nombrar el dato', function (WorkforceConflict $conflicto, string $mensaje): void {
    expect($conflicto->getMessage())->toBe($mensaje);
})->with([
    'documento ya registrado' => [
        EmployeeNationalIdAlreadyTaken::make(),
        'Ya existe otro empleado con ese documento de identidad.',
    ],
    'escrituras cruzadas' => [
        ConcurrentEmployeeWrite::make(),
        'Otra escritura simultanea de la plantilla ha chocado con esta. No se ha guardado nada: repitela.',
    ],
])->group('RF-GP-01', 'RL-08');
