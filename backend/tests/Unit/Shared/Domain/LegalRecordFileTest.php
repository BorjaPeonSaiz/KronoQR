<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\LegalRecordFile;

/*
 * La exportacion para la Inspeccion ya escrita, tal como cruza de `Compliance`
 * a `Reporting` en la generacion en diferido (RF-IN-05, V5-PA-4). Lo que se
 * fija: las filas son tramos mas correcciones, y el asiento lleva **las mismas
 * claves que la descarga sincrona**, sin una sola fila del registro.
 */

it('cuenta como filas los tramos mas las correcciones', function (): void {
    $fichero = new LegalRecordFile('registro-horario-2026-03-01_2026-03-31.csv', '2026-03-01', '2026-03-31', null, 120, 7, 15);

    expect($fichero->rows())->toBe(127);
})->group('RF-IN-05');

it('describe el asiento con las claves de la exportacion sincrona', function (?string $persona, string $alcance): void {
    $fichero = new LegalRecordFile('registro-horario-2026-03-01_2026-03-31.csv', '2026-03-01', '2026-03-31', $persona, 120, 7, 15);

    expect($fichero->auditFacts())->toBe([
        'period_from' => '2026-03-01',
        'period_to' => '2026-03-31',
        'scope' => $alcance,
        'employee_uuid' => $persona,
        'shift_entry_rows' => 120,
        'correction_rows' => 7,
        'employees_exported' => 15,
    ]);
})->with([
    'la plantilla' => [null, 'all'],
    'una persona' => ['019a0000-0000-7000-8000-000000000002', 'employee'],
])->group('RF-IN-05', 'RS-05');

it('no admite un fichero sin nombre ni recuentos negativos', function (string $nombre, int $tramos, int $correcciones, int $personas): void {
    expect(static fn () => new LegalRecordFile($nombre, '2026-03-01', '2026-03-31', null, $tramos, $correcciones, $personas))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin nombre' => ['', 0, 0, 0],
    'tramos negativos' => ['r.csv', -1, 0, 0],
    'correcciones negativas' => ['r.csv', 0, -1, 0],
    'personas negativas' => ['r.csv', 0, 0, -1],
])->group('RF-IN-05');

it('admite un periodo sin nada que exportar', function (): void {
    // «No hay nada» tambien es una afirmacion que hay que poder entregar.
    expect((new LegalRecordFile('r.csv', '2026-03-01', '2026-03-31', null, 0, 0, 0))->rows())->toBe(0);
})->group('RF-IN-05');
