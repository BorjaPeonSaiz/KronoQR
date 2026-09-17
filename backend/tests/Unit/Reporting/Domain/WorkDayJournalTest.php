<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Exception\InvalidDateRange;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use Tests\Support\Time\Instants;

/*
 * El lado de lectura del registro horario, sin base de datos (RF-PA-03).
 *
 * Lo que se defiende aqui es que la pantalla no pueda enseñar un total que no
 * cuadre con sus partes (RN-06) y que el rango que se consulta no pueda ser
 * cualquier cosa. Las dos son decisiones del dominio y por eso se prueban sin
 * arrancar el framework.
 */

/**
 * Un tramo vigente del detalle, con lo minimo para poder hablar de su duracion.
 *
 * `closedBy` acompaña a la hora de salida porque el objeto de valor lo exige
 * desde RF-AT-12: un tramo con salida tiene que decir que lo cerro, y uno
 * abierto no puede decirlo (ADR-024).
 */
function tramoDelDetalle(
    ?int $duracion,
    string $estado = 'closed',
    ?string $salida = '2026-03-14 14:00',
    string $abiertoPor = JournalShiftEntry::OPENED_BY_CLOCK_IN,
    string $cerradoPor = JournalShiftEntry::CLOSED_BY_CLOCK_OUT,
): JournalShiftEntry {
    return new JournalShiftEntry(
        uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
        version: 1,
        status: $estado,
        siteId: 1,
        timeZone: 'Europe/Madrid',
        clockedInAt: Instants::utc('2026-03-14 06:00'),
        clockInRecordedAt: null,
        clockInSource: 'qr_kiosk',
        clockedOutAt: $salida === null ? null : Instants::utc($salida),
        clockOutRecordedAt: null,
        clockOutSource: $salida === null ? null : 'qr_kiosk',
        durationMinutes: $duracion,
        recordedAt: Instants::utc('2026-03-14 14:00'),
        openedBy: $abiertoPor,
        closedBy: $salida === null ? null : $cerradoPor,
    );
}

/** @param  list<JournalShiftEntry>  $tramos */
function jornadaDelDetalle(array $tramos): JournalWorkDay
{
    return new JournalWorkDay(
        workDate: '2026-03-14',
        timeZone: 'Europe/Madrid',
        recalculatedAt: null,
        shiftEntries: $tramos,
        corrections: [],
    );
}

it('calcula el total del dia sumando sus tramos vigentes', function (): void {
    // RN-06: el total ES la suma, no un campo que alguien rellena. Asi el panel
    // no puede pintar un numero que no cuadre con las filas que tiene debajo.
    $jornada = jornadaDelDetalle([tramoDelDetalle(240), tramoDelDetalle(270)]);

    expect($jornada->totalMinutes())->toBe(510)
        ->and($jornada->shiftCount())->toBe(2);
})->group('RF-PA-03', 'RN-06');

it('cuenta cero por un turno abierto y avisa de que lo hay', function (): void {
    // RN-01. Inventarle una duracion a un turno en curso seria dar por terminado
    // lo que no ha terminado, y ese numero acaba en una nomina. Pero el panel
    // tiene que decir que el total de hoy todavia va a subir.
    $jornada = jornadaDelDetalle([tramoDelDetalle(480), tramoDelDetalle(null, 'open', null)]);

    expect($jornada->totalMinutes())->toBe(480)
        ->and($jornada->hasOpenShift())->toBeTrue()
        ->and($jornada->hasIncident())->toBeFalse();
})->group('RF-PA-03', 'RN-01', 'RN-06');

it('avisa de la jornada con un tramo por revisar sin tratarla como un error', function (): void {
    // RN-07 y RN-08: `anomalous` no es un fallo. El tramo esta cerrado con sus
    // marcas reales y lo que dice es que una persona tiene que mirarlo, asi que
    // sus minutos cuentan igual.
    $jornada = jornadaDelDetalle([tramoDelDetalle(900, 'anomalous')]);

    expect($jornada->hasIncident())->toBeTrue()
        ->and($jornada->totalMinutes())->toBe(900);
})->group('RF-PA-03', 'RN-07');

it('conserva la jornada cuyos tramos se anularon todos, con total cero', function (): void {
    // Regla dura 5: ocultarla haria desaparecer de la pantalla justo el dia que
    // alguien necesita explicar.
    $jornada = jornadaDelDetalle([]);

    expect($jornada->totalMinutes())->toBe(0)
        ->and($jornada->shiftCount())->toBe(0)
        ->and($jornada->hasOpenShift())->toBeFalse();
})->group('RF-PA-03', 'RL-04');

it('cuenta los dias del rango por los dos extremos', function (): void {
    // Es un intervalo CERRADO: del 1 al 31 de marzo son 31 jornadas, no 30. Un
    // rango abierto por la derecha dejaria fuera el ultimo dia, que es
    // exactamente el que alguien acaba de consultar.
    expect(DateRange::between('2026-03-01', '2026-03-31')->days())->toBe(31)
        ->and(DateRange::between('2026-03-14', '2026-03-14')->days())->toBe(1);
})->group('RF-PA-03');

it('resuelve el rango por omision como los 31 dias que terminan en el ultimo', function (): void {
    // `endingOn('2026-03-31', 31)` es marzo entero, no marzo mas un dia.
    $rango = DateRange::endingOn('2026-03-31', 31);

    expect($rango->isoFrom())->toBe('2026-03-01')
        ->and($rango->isoTo())->toBe('2026-03-31');
})->group('RF-PA-03');

it('rechaza un rango invertido, una fecha que no existe y uno mas ancho que el techo', function (array $rango): void {
    // El techo no es una regla de negocio: es lo que impide que una URL
    // manipulada pida el historico completo de una persona —cuatro años de
    // retencion (RL-02)— en una sola respuesta.
    expect(static fn () => DateRange::between($rango[0], $rango[1]))
        ->toThrow(InvalidDateRange::class);
})->with([
    'invertido' => [['2026-03-31', '2026-03-01']],
    'inexistente' => [['2026-02-30', '2026-03-01']],
    'con otro formato' => [['14/03/2026', '2026-03-31']],
    'mas de 366 dias' => [['2025-01-01', '2026-03-01']],
])->group('RF-PA-03');

it('dice quien abrio y quien cerro cada tramo, y no admite otra cosa', function (): void {
    // RF-AT-12 y ADR-024. Dos tramos consecutivos pueden ser una jornada con
    // pausa o dos jornadas distintas, y en la tabla se ven igual: lo que los
    // distingue es esto. Los valores son los del contrato
    // (`WorkDayShiftEntry.opened_by` / `closed_by`) y no una cadena libre,
    // porque un valor inventado llegaria al panel y al portal sin que nadie lo
    // parara.
    $pausa = tramoDelDetalle(240, salida: '2026-03-14 10:00', cerradoPor: JournalShiftEntry::CLOSED_BY_BREAK_START);
    $vuelta = tramoDelDetalle(240, abiertoPor: JournalShiftEntry::OPENED_BY_BREAK_END);

    expect($pausa->closedBy)->toBe('break_start')
        ->and($pausa->openedBy)->toBe('clock_in')
        ->and($vuelta->openedBy)->toBe('break_end')
        ->and(static fn () => tramoDelDetalle(240, abiertoPor: 'break_start'))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn () => tramoDelDetalle(240, cerradoPor: 'break_end'))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-AT-12', 'RF-PA-03');

it('exige que un tramo cerrado diga que lo cerro y que uno abierto no lo diga', function (): void {
    // Las dos mitades de la misma verdad: el contrato declara `closed_by`
    // obligatorio y nulo SOLO mientras el tramo sigue abierto. Un nulo en un
    // tramo terminado haria que el panel pintara «en curso» algo que termino, y
    // un valor en uno abierto diria que alguien ya se fue.
    expect(static fn (): JournalShiftEntry => new JournalShiftEntry(
        uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
        version: 1,
        status: 'closed',
        siteId: 1,
        timeZone: 'Europe/Madrid',
        clockedInAt: Instants::utc('2026-03-14 06:00'),
        clockInRecordedAt: null,
        clockInSource: 'qr_kiosk',
        clockedOutAt: Instants::utc('2026-03-14 14:00'),
        clockOutRecordedAt: null,
        clockOutSource: 'qr_kiosk',
        durationMinutes: 480,
        recordedAt: Instants::utc('2026-03-14 14:00'),
    ))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): JournalShiftEntry => new JournalShiftEntry(
            uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
            version: 1,
            status: 'open',
            siteId: 1,
            timeZone: 'Europe/Madrid',
            clockedInAt: Instants::utc('2026-03-14 06:00'),
            clockInRecordedAt: null,
            clockInSource: 'qr_kiosk',
            clockedOutAt: null,
            clockOutRecordedAt: null,
            clockOutSource: null,
            durationMinutes: null,
            recordedAt: Instants::utc('2026-03-14 06:00'),
            closedBy: JournalShiftEntry::CLOSED_BY_CLOCK_OUT,
        ))->toThrow(InvalidArgumentException::class);
})->group('RF-AT-12', 'RF-PA-03');
