<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\ScanDiscardBudgetProbe;

/*
 * **`product:doctor` avisa de un cupo de descartes por encima del techo**
 * (RN-22, ADR-061, A6 del 24-10-2026). El servidor aplica el techo; la sonda
 * solo lo dice. Aviso y nunca fallo: no impide arrancar ni actualizar.
 */

it('avisa de un SCAN_DISCARD_DAILY_BUDGET por encima del techo', function (mixed $configurado): void {
    $hallazgos = (new ScanDiscardBudgetProbe($configurado, 20))->run();

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]->status)->toBe(DoctorStatus::Warning)
        ->and($hallazgos[0]->details)->toBe(['configured' => 25, 'ceiling' => 20]);
})->with(['texto' => ['25'], 'entero' => [25], 'con espacios' => [' 25 ']])->group('RN-22');

it('no avisa con el cupo en el techo, por debajo, a cero o ilegible', function (mixed $configurado): void {
    $hallazgos = (new ScanDiscardBudgetProbe($configurado, 20))->run();

    expect($hallazgos[0]->status)->toBe(DoctorStatus::Ok);
})->with(['techo' => ['20'], 'de serie' => ['3'], 'apagado' => ['0'], 'texto' => ['muchos'], 'nulo' => [null]])->group('RN-22');
