<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Time\Instants;

/*
 * El tramo, rehidratado desde la base de datos.
 *
 * `open()`, `close()` y `markAnomalous()` son `@internal` de WorkDay y aqui no
 * se llaman: lo que se prueba de ellos entra por el agregado (WorkDayTest).
 * Quien lo comprueba es AggregateBoundaryTest.
 */

it('aporta cero al total del dia mientras siga abierto', function (): void {
    // Preguntar «lo que lleva hasta ahora» exigiria el reloj, y el registro
    // legal cuenta lo fichado, no lo que va corriendo.
    $entry = ShiftEntryFactory::new()->openSince('2026-03-14 07:00')->build();

    expect($entry->workedDuration()->minutes)->toBe(0)
        ->and($entry->period())->toBeNull()
        ->and($entry->isOpen())->toBeTrue();
})->group('RN-06');

it('mide su duracion cuando esta cerrado', function (): void {
    $entry = ShiftEntryFactory::new()->worked('2026-03-14 07:00', '2026-03-14 15:00')->build();

    expect($entry->workedDuration()->minutes)->toBe(480)
        ->and($entry->isOpen())->toBeFalse();
})->group('RN-09');

it('rechaza rehidratarse con la salida antes de la entrada', function (): void {
    // La fila puede venir de una importacion o de una version anterior del
    // codigo: RN-03 se comprueba tan pronto como se lee, no solo al escribir.
    expect(fn (): ShiftEntry => ShiftEntryFactory::new()
        ->worked('2026-03-14 15:00', '2026-03-14 07:00')
        ->build())
        ->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-03');

it('rechaza rehidratarse con una entrada que no viene en UTC', function (): void {
    expect(fn (): ShiftEntry => ShiftEntryFactory::new()
        ->openSinceInstant(new DateTimeImmutable('2026-03-14 07:00', Instants::madrid()))
        ->build())
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04');

it('conserva el origen de la entrada y el de la salida por separado', function (): void {
    // RF-AT-11: se entra con la tarjeta y se sale con el PIN porque la tarjeta
    // se quedo en la taquilla.
    $entry = ShiftEntryFactory::new()
        ->worked('2026-03-14 07:00', '2026-03-14 15:00')
        ->scannedFrom(ScanOrigin::QR_KIOSK)
        ->leftFrom(ScanOrigin::PIN_KIOSK)
        ->build();

    expect($entry->clockInSource())->toBe(ScanOrigin::QR_KIOSK)
        ->and($entry->clockOutSource())->toBe(ScanOrigin::PIN_KIOSK);
})->group('RF-AT-11');

it('no tiene origen de salida mientras siga abierto', function (): void {
    $entry = ShiftEntryFactory::new()->openSince('2026-03-14 07:00')->build();

    expect($entry->clockOutSource())->toBeNull()
        ->and($entry->clockedOutAt())->toBeNull();
})->group('RF-AT-02');

it('conserva la version con la que se rehidrato', function (): void {
    // RN-13: una correccion crea una version nueva y conserva la anterior.
    $entry = ShiftEntryFactory::new()->worked('2026-03-14 07:00', '2026-03-14 15:00')->atVersion(3)->build();

    expect($entry->version())->toBe(3);
})->group('RN-13');

it('hereda la jornada que le da el agregado en vez de derivarla de su entrada', function (): void {
    // ADR-024. La vuelta de una pausa a las 02:30 continua la jornada de ayer;
    // si el tramo derivase su work_date de su propio clocked_in_at, fichar el
    // descanso partiria la jornada por la puerta de atras.
    $yesterday = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());

    $entry = ShiftEntryFactory::new()
        ->onWorkDay($yesterday)
        ->worked('2026-03-15 01:30', '2026-03-15 05:00')
        ->build();

    expect($entry->workDate()->isoDate)->toBe('2026-03-14');
})->group('RN-05', 'RF-AT-08');

it('cubre su inicio y deja fuera su fin', function (string $instant, bool $covered): void {
    $entry = ShiftEntryFactory::new()->worked('2026-03-14 08:00', '2026-03-14 14:00')->build();

    expect($entry->coversInstant(Instants::utc($instant)))->toBe($covered);
})->with([
    'un segundo antes de la entrada' => ['2026-03-14 07:59:59', false],
    'la entrada exacta' => ['2026-03-14 08:00:00', true],
    'la salida exacta' => ['2026-03-14 14:00:00', false],
])->group('RN-02');

it('cubre todo instante posterior a su entrada mientras siga abierto', function (string $instant, bool $covered): void {
    // Un tramo sin fin llega al infinito: es lo que hace que un escaneo offline
    // que llega tarde no pueda colarse dentro de el.
    $entry = ShiftEntryFactory::new()->openSince('2026-03-14 08:00')->build();

    expect($entry->coversInstant(Instants::utc($instant)))->toBe($covered);
})->with([
    'un segundo antes de la entrada' => ['2026-03-14 07:59:59', false],
    'la entrada exacta' => ['2026-03-14 08:00:00', true],
    'dias despues' => ['2026-03-20 08:00:00', true],
])->group('RN-02');

it('sigue vivo despues de un instante solo si su salida es posterior', function (string $instant, bool $extends): void {
    $entry = ShiftEntryFactory::new()->worked('2026-03-14 08:00', '2026-03-14 14:00')->build();

    expect($entry->extendsBeyond(Instants::utc($instant)))->toBe($extends);
})->with([
    'un segundo antes de la salida' => ['2026-03-14 13:59:59', true],
    'la salida exacta' => ['2026-03-14 14:00:00', false],
    'un segundo despues de la salida' => ['2026-03-14 14:00:01', false],
])->group('RN-02');

it('sigue vivo siempre mientras este abierto', function (): void {
    $entry = ShiftEntryFactory::new()->openSince('2026-03-14 08:00')->build();

    expect($entry->extendsBeyond(Instants::utc('2027-01-01 00:00')))->toBeTrue();
})->group('RN-02');

it('rehidrata los estados historicos sin convertirlos en vigentes', function (ShiftEntryStatus $status, bool $current): void {
    // ADR-026: «voided» dice que el tramo no ocurrio y «superseded» que ocurrio
    // y otra version lo sustituye. Colapsarlos haria indistinguibles ante
    // Inspeccion una anulacion y una correccion.
    expect($status->isCurrent())->toBe($current);
})->with([
    'abierto' => [ShiftEntryStatus::OPEN, true],
    'cerrado' => [ShiftEntryStatus::CLOSED, true],
    'anomalo' => [ShiftEntryStatus::ANOMALOUS, true],
    'anulado' => [ShiftEntryStatus::VOIDED, false],
    'sustituido' => [ShiftEntryStatus::SUPERSEDED, false],
])->group('RN-13');

it('solo considera abierto el estado abierto', function (ShiftEntryStatus $status, bool $open): void {
    expect($status->isOpen())->toBe($open);
})->with([
    'abierto' => [ShiftEntryStatus::OPEN, true],
    'cerrado' => [ShiftEntryStatus::CLOSED, false],
    'anomalo' => [ShiftEntryStatus::ANOMALOUS, false],
    'anulado' => [ShiftEntryStatus::VOIDED, false],
    'sustituido' => [ShiftEntryStatus::SUPERSEDED, false],
])->group('RN-01');

it('conserva el uuid y el empleado con los que se rehidrato', function (): void {
    $entry = ShiftEntryFactory::new()
        ->withUuid('0199a0c1-0000-7000-8000-000000000001')
        ->forEmployee('0199a0c0-0000-7000-8000-00000000000a')
        ->worked('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    expect($entry->uuid())->toBe('0199a0c1-0000-7000-8000-000000000001')
        ->and($entry->employeeUuid())->toBe('0199a0c0-0000-7000-8000-00000000000a');
})->group('RN-13');

it('marca su estado como anomalo cuando se rehidrata uno ya marcado', function (): void {
    $entry = ShiftEntryFactory::new()
        ->worked('2026-03-14 07:00', '2026-03-15 09:00')
        ->markedAnomalous()
        ->build();

    expect($entry->status())->toBe(ShiftEntryStatus::ANOMALOUS)
        ->and($entry->isOpen())->toBeFalse();
})->group('RN-08');
