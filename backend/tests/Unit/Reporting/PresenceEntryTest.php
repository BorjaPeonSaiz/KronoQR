<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;

/*
 * Las invariantes de una fila de presencia (RF-PA-01).
 *
 * **Estan en el constructor y no en la consulta** a proposito: la misma fila la
 * construyen dos caminos —el listado y el difusor del WebSocket— y una
 * comprobacion que viviera en uno de los dos dejaria al otro sin ella. Aqui son
 * imposibles de saltarse.
 */

const PRESENCIA_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

const PRESENCIA_TRAMO = '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11';

/**
 * Una fila valida de alguien que esta dentro, para variar un campo cada vez.
 *
 * @param  array<string, mixed>  $overrides
 */
function filaDePresencia(array $overrides = []): PresenceEntry
{
    /** @var array{employeeUuid: string, fullName: string, departmentId: int|null, departmentName: string|null, status: PresenceStatus, shiftEntryUuid: string|null, clockedInAt: DateTimeImmutable|null, origin: string|null, deviceUuid: string|null, deviceName: string|null} $campos */
    $campos = array_merge([
        'employeeUuid' => PRESENCIA_UUID,
        'fullName' => 'Youssef Amrani',
        'departmentId' => 3,
        'departmentName' => 'Cocina',
        'status' => PresenceStatus::Present,
        'shiftEntryUuid' => PRESENCIA_TRAMO,
        'clockedInAt' => new DateTimeImmutable('2026-03-14T05:00:00Z'),
        'origin' => 'qr_kiosk',
        'deviceUuid' => null,
        'deviceName' => null,
    ], $overrides);

    return new PresenceEntry(...$campos);
}

it('acepta a quien esta dentro con su tramo entero', function (): void {
    $fila = filaDePresencia();

    expect($fila->status)->toBe(PresenceStatus::Present)
        ->and($fila->shiftEntryUuid)->toBe(PRESENCIA_TRAMO);
})->group('RF-PA-01');

it('acepta a quien esta fuera sin ningun dato de tramo', function (): void {
    $fila = filaDePresencia([
        'status' => PresenceStatus::Absent,
        'shiftEntryUuid' => null,
        'clockedInAt' => null,
        'origin' => null,
    ]);

    expect($fila->status)->toBe(PresenceStatus::Absent)
        ->and($fila->clockedInAt)->toBeNull();
})->group('RF-PA-01');

it('rechaza un ausente con hora de entrada', function (): void {
    // Es el estado que la pantalla pintaria como si la persona estuviera dentro.
    filaDePresencia(['status' => PresenceStatus::Absent, 'shiftEntryUuid' => null, 'origin' => null]);
})->throws(InvalidArgumentException::class)->group('RF-PA-01');

it('rechaza un presente sin tramo', function (): void {
    filaDePresencia(['shiftEntryUuid' => null]);
})->throws(InvalidArgumentException::class)->group('RF-PA-01');

it('rechaza medio departamento', function (): void {
    // Sin nombre, la pantalla enseñaria un numero; sin identificador, el panel no
    // podria filtrar por el.
    filaDePresencia(['departmentName' => null]);
})->throws(InvalidArgumentException::class)->group('RF-PA-01');

it('acepta a quien no tiene departamento', function (): void {
    // Es un estado legitimo de una ficha recien creada, y solo lo ve una cuenta
    // sin restriccion de alcance (RF-ID-03).
    $fila = filaDePresencia(['departmentId' => null, 'departmentName' => null]);

    expect($fila->departmentId)->toBeNull();
})->group('RF-PA-01', 'RF-ID-03');

it('acepta un tramo abierto sin quiosco, que es un alta manual', function (): void {
    $fila = filaDePresencia(['origin' => 'manual_admin']);

    expect($fila->deviceUuid)->toBeNull()
        ->and($fila->origin)->toBe('manual_admin');
})->group('RF-PA-01', 'RF-PA-04');

it('rechaza un quiosco sin tramo abierto', function (): void {
    // Nadie ficha en un quiosco sin abrir un tramo.
    filaDePresencia([
        'status' => PresenceStatus::Absent,
        'shiftEntryUuid' => null,
        'clockedInAt' => null,
        'origin' => null,
        'deviceUuid' => '0199f0d3-3c71-7e52-9a13-6f7a8b9c0d12',
        'deviceName' => 'Entrada de personal',
    ]);
})->throws(InvalidArgumentException::class)->group('RF-PA-01');

it('rechaza una fila sin nombre que pintar', function (): void {
    filaDePresencia(['fullName' => '   ']);
})->throws(InvalidArgumentException::class)->group('RF-PA-01');
