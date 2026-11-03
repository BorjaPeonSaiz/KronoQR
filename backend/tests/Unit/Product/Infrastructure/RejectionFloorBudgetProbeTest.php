<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\RejectionFloorBudgetProbe;

/*
 * **`product:doctor` avisa cuando el suelo de los rechazos acerca un lote del
 * quiosco a su corte de 8 s** (RS-03, A-13, enmienda de ADR-005; revision de
 * la 33.6). Cada rechazo del fichaje espera el doble del suelo; un lote de
 * rechazos, eso por su tamaño. Aviso y nunca fallo: el quiosco reintenta.
 *
 * Que el corte de la sonda sea el de la PWA lo ata
 * `KioskRequestTimeoutParityTest` (Architecture), que lee el cliente.
 */

it('no avisa con el suelo de serie ni en la cota de 60 con el lote de 50', function (int $suelo, int $peor): void {
    $hallazgos = (new RejectionFloorBudgetProbe($suelo, 50))->run();

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]->id)->toBe('kiosk.rejection_floor_budget')
        ->and($hallazgos[0]->status)->toBe(DoctorStatus::Ok)
        ->and($hallazgos[0]->details)->toBe([
            'floor_ms' => $suelo,
            'batch_size' => 50,
            'worst_case_ms' => $peor,
            'timeout_ms' => 8_000,
            'max_floor_ms' => 60,
        ]);
})->with([
    'de serie' => [25, 2_500],
    'en la cota, justo el 75 %' => [60, 6_000],
    'apagado' => [0, 0],
])->group('RS-03');

it('avisa en cuanto el peor lote pasa del 75 % del corte', function (int $suelo, int $lote, int $peor, int $maximo): void {
    $hallazgos = (new RejectionFloorBudgetProbe($suelo, $lote))->run();

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]->status)->toBe(DoctorStatus::Warning)
        ->and($hallazgos[0]->params)->toBe([
            'floor_ms' => $suelo,
            'batch_size' => $lote,
            'worst_case_ms' => $peor,
            'timeout_ms' => 8_000,
            'max_floor_ms' => $maximo,
        ])
        ->and($hallazgos[0]->details)->toBe($hallazgos[0]->params);
})->with([
    'un milisegundo por encima de la cota' => [61, 50, 6_100, 60],
    'pasado del corte' => [100, 50, 10_000, 60],
    // Con lotes mas grandes la cota baja: el maximo sale de la configuracion.
    'suelo de serie con lotes de 200' => [25, 200, 10_000, 15],
])->group('RS-03');

it('trata un suelo negativo como apagado y un lote de cero como uno', function (): void {
    $hallazgo = (new RejectionFloorBudgetProbe(-10, 0))->run()[0];

    expect($hallazgo->status)->toBe(DoctorStatus::Ok)
        ->and($hallazgo->details['floor_ms'] ?? null)->toBe(0)
        ->and($hallazgo->details['batch_size'] ?? null)->toBe(1)
        ->and($hallazgo->details['max_floor_ms'] ?? null)->toBe(3_000);
})->group('RS-03');

it('es de la familia kiosk', function (): void {
    expect((new RejectionFloorBudgetProbe(25, 50))->family())->toBe('kiosk');
})->group('RS-03');
