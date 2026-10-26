<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Compliance\Domain\ValueObject\ExportedClockSource;
use App\Modules\Compliance\Domain\ValueObject\ExportedDuration;
use App\Modules\Compliance\Domain\ValueObject\ExportedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\ExportedShiftEntryStatus;
use App\Modules\Compliance\Domain\ValueObject\ExportedSubject;

/*
 * El tramo de la exportacion legal no admite estados imposibles (H5 de la
 * verificacion de la 2.1.0, RF-IN-05, RL-01, RL-04).
 *
 * El estado y los origenes son enumerados y no cadenas libres. `Compliance` no
 * puede importar los de `Attendance` (doc 02 §1.6) y repite sus valores: estas
 * pruebas son lo que impide que diverjan, porque un origen nuevo en el quiosco
 * que no llegara aqui haria fallar la exportacion legal al leer la primera fila
 * que lo lleve.
 */

function tramoExportado(
    int $orden = 1,
    ExportedShiftEntryStatus $estado = ExportedShiftEntryStatus::Closed,
    string $salidaLocal = '2026-01-14 16:00',
    string $salidaUtc = '2026-01-14T15:00:00Z',
): ExportedShiftEntry {
    return new ExportedShiftEntry(
        subject: new ExportedSubject(
            employeeCode: 'E0001',
            lastName: 'Fernandez Ruiz',
            firstName: 'Lucia',
            employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            siteName: 'Hotel de pruebas',
            departmentName: null,
            timezone: 'Europe/Madrid',
            workDate: '2026-01-14',
        ),
        entryNumber: $orden,
        shiftEntryUuid: '0199f0c2-0000-7000-8000-000000000001',
        localClockedInAt: '2026-01-14 08:00',
        localClockedOutAt: $salidaLocal,
        utcClockedInAt: '2026-01-14T07:00:00Z',
        utcClockedOutAt: $salidaUtc,
        duration: $salidaUtc === '' ? ExportedDuration::absent() : ExportedDuration::ofMinutes(480),
        dayTotal: ExportedDuration::ofMinutes(480),
        status: $estado,
        clockInSource: ExportedClockSource::QrKiosk,
        clockOutSource: $salidaUtc === '' ? null : ExportedClockSource::PinKiosk,
    );
}

it('repite los origenes de una marca del modulo de fichaje', function (): void {
    expect(array_map(static fn (ExportedClockSource $source): string => $source->value, ExportedClockSource::cases()))
        ->toBe(array_map(static fn (ScanOrigin $origin): string => $origin->value, ScanOrigin::cases()));
})->group('RF-IN-05', 'RL-01');

it('repite los estados de un tramo salvo la version sustituida, que no es una fila de tramo', function (): void {
    $vigentes = array_filter(
        array_map(static fn (ShiftEntryStatus $status): string => $status->value, ShiftEntryStatus::cases()),
        static fn (string $status): bool => $status !== ShiftEntryStatus::SUPERSEDED->value,
    );

    expect(array_map(static fn (ExportedShiftEntryStatus $status): string => $status->value, ExportedShiftEntryStatus::cases()))
        ->toBe($vigentes)
        ->and(ExportedShiftEntryStatus::tryFrom('superseded'))->toBeNull();
})->group('RF-IN-05', 'RL-04');

it('numera los tramos de la jornada desde 1', function (): void {
    expect(tramoExportado(orden: 1)->entryNumber)->toBe(1)
        ->and(static fn (): ExportedShiftEntry => tramoExportado(orden: 0))
        ->toThrow(InvalidArgumentException::class, 'empieza en 1, y llego 0.');
})->group('RF-IN-05', 'RL-06');

it('no admite un tramo abierto con hora de salida', function (string $salidaLocal, string $salidaUtc): void {
    expect(static fn (): ExportedShiftEntry => tramoExportado(
        estado: ExportedShiftEntryStatus::Open,
        salidaLocal: $salidaLocal,
        salidaUtc: $salidaUtc,
    ))->toThrow(InvalidArgumentException::class, 'Un tramo abierto no tiene hora de salida.');
})->with([
    'con las dos horas de salida' => ['2026-01-14 16:00', '2026-01-14T15:00:00Z'],
    'solo con la local' => ['2026-01-14 16:00', ''],
    'solo con la UTC' => ['', '2026-01-14T15:00:00Z'],
])->group('RF-IN-05', 'RL-06');

it('admite el tramo abierto sin salida y el anulado sin salida', function (ExportedShiftEntryStatus $estado): void {
    $tramo = tramoExportado(estado: $estado, salidaLocal: '', salidaUtc: '');

    expect($tramo->status)->toBe($estado)
        ->and($tramo->clockOutSource)->toBeNull();
})->with([
    'abierto' => [ExportedShiftEntryStatus::Open],
    // Se anula un tramo que nunca se cerro: figura, sin salida y sin horas.
    'anulado' => [ExportedShiftEntryStatus::Voided],
])->group('RF-IN-05', 'RL-04');
