<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\DailyTotalsReconciled;
use App\Modules\Attendance\Domain\Event\DailyTotalsSnapshot;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/*
 * **La fotografia de una fila de `daily_totals`** que viaja al asiento de la
 * reconciliacion (RN-06, RL-04, RF-PR-02, tarea 3.6).
 *
 * Por que esto merece pruebas propias: desde que la correccion no reescribe nada
 * cuando la sospecha se deshace sola, el asiento es la **unica** copia de lo que
 * la proyeccion afirmaba —`audit_log` es solo-append y encadenado por hash
 * (ADR-027)—, y lo que llega a ese asiento son estos seis campos y estos dos
 * accesores. Un instante redondeado al segundo, un desplazamiento distinto de
 * UTC o un `null` convertido en cadena vacia no romperian ninguna prueba de
 * arriba y dejarian un registro con valor legal que no se puede contrastar con
 * los tramos que lo originaron.
 */

it('conserva los seis campos tal como se le dan', function (): void {
    $snapshot = new DailyTotalsSnapshot(
        totalMinutes: 480,
        shiftCount: 2,
        firstClockInAt: new DateTimeImmutable('2026-03-14 06:00:00', new DateTimeZone('UTC')),
        lastClockOutAt: new DateTimeImmutable('2026-03-14 14:00:00', new DateTimeZone('UTC')),
        hasOpenShift: false,
        hasIncident: true,
    );

    // Los seis, uno a uno: son los seis que compara la reconciliacion, y un
    // asiento con cinco no permite reconstruir la fila mala.
    expect($snapshot->totalMinutes)->toBe(480)
        ->and($snapshot->shiftCount)->toBe(2)
        ->and($snapshot->hasOpenShift)->toBeFalse()
        ->and($snapshot->hasIncident)->toBeTrue()
        ->and($snapshot->firstClockInAtIso())->toBe('2026-03-14T06:00:00.000000+00:00')
        ->and($snapshot->lastClockOutAtIso())->toBe('2026-03-14T14:00:00.000000+00:00');
})->group('RN-06', 'RL-04', 'RF-PR-02');

it('conserva los microsegundos de los dos instantes', function (): void {
    // Los `TIMESTAMPTZ` del producto se guardan con seis decimales y la
    // comparacion de integridad se hace al microsegundo
    // (`DailyTotalsDivergence::sameInstant()`). Un asiento que redondeara al
    // segundo diria que la fila mala y el tramo coinciden cuando no lo hacen.
    $snapshot = new DailyTotalsSnapshot(
        totalMinutes: 1,
        shiftCount: 1,
        firstClockInAt: new DateTimeImmutable('2026-03-14 06:00:00.123456', new DateTimeZone('UTC')),
        lastClockOutAt: new DateTimeImmutable('2026-03-14 06:01:00.000001', new DateTimeZone('UTC')),
        hasOpenShift: false,
        hasIncident: false,
    );

    expect($snapshot->firstClockInAtIso())->toBe('2026-03-14T06:00:00.123456+00:00')
        ->and($snapshot->lastClockOutAtIso())->toBe('2026-03-14T06:01:00.000001+00:00');
})->group('RN-06', 'RL-04', 'RF-PR-02');

it('escribe el desplazamiento que trae el instante, sin reinterpretarlo', function (): void {
    // Regla dura 3: todo instante del producto viaja en UTC, y el accesor lo
    // escribe **con su desplazamiento explicito** en lugar de darlo por supuesto.
    // Si algun dia llegara aqui un instante en hora de Madrid, el asiento diria
    // `+01:00` y se veria, en lugar de afirmar en silencio una hora que no es.
    $madrid = new DailyTotalsSnapshot(
        totalMinutes: 480,
        shiftCount: 1,
        firstClockInAt: new DateTimeImmutable('2026-03-14 07:00:00', new DateTimeZone('Europe/Madrid')),
        lastClockOutAt: null,
        hasOpenShift: true,
        hasIncident: false,
    );

    expect($madrid->firstClockInAtIso())->toBe('2026-03-14T07:00:00.000000+01:00')
        // Y es el mismo instante que las 06:00 UTC: lo que cambia es como se
        // escribe, no lo que dice.
        ->and($madrid->firstClockInAt?->getTimestamp())
        ->toBe((new DateTimeImmutable('2026-03-14 06:00:00', new DateTimeZone('UTC')))->getTimestamp());
})->group('RN-06', 'RL-04', 'RF-PR-02');

it('distingue un instante ausente de uno a cero', function (): void {
    // `last_out_at` a nulo es lo que dice «este turno sigue abierto», y es uno de
    // los seis campos que la reconciliacion compara: convertirlo en una cadena
    // vacia o en una fecha de epoch convertiria un turno en curso en uno
    // terminado dentro del propio asiento.
    $open = new DailyTotalsSnapshot(
        totalMinutes: 0,
        shiftCount: 1,
        firstClockInAt: new DateTimeImmutable('2026-03-14 06:00:00', new DateTimeZone('UTC')),
        lastClockOutAt: null,
        hasOpenShift: true,
        hasIncident: false,
    );

    expect($open->lastClockOutAtIso())->toBeNull()
        ->and($open->firstClockInAtIso())->not->toBeNull();

    // Y la jornada anulada por completo: los dos instantes ausentes, cero
    // minutos y cero tramos, que es lo que la correccion escribe sin borrar la
    // fila (regla dura 5).
    $voided = new DailyTotalsSnapshot(
        totalMinutes: 0,
        shiftCount: 0,
        firstClockInAt: null,
        lastClockOutAt: null,
        hasOpenShift: false,
        hasIncident: false,
    );

    expect($voided->firstClockInAtIso())->toBeNull()
        ->and($voided->lastClockOutAtIso())->toBeNull()
        ->and($voided->totalMinutes)->toBe(0)
        ->and($voided->shiftCount)->toBe(0);
})->group('RN-06', 'RL-04', 'RF-PR-02');

it('dice que faltaba la fila cuando el evento no trae un antes', function (): void {
    // `rowWasMissing()` se **deriva** de `before` y no viaja aparte: dos formas
    // de decir lo mismo acaban contradiciendose, y esta es la que distingue «la
    // proyeccion decia un dia a cero» de «la proyeccion no decia nada», que es
    // la peor de las divergencias (un dia con tramos que el panel muestra vacio).
    $after = new DailyTotalsSnapshot(480, 1, null, null, false, false);

    $missing = new DailyTotalsReconciled(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        workDate: WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        divergentFields: ['row'],
        before: null,
        after: $after,
        reconciledAt: new DateTimeImmutable('2026-03-15 03:50:00', new DateTimeZone('UTC')),
    );

    expect($missing->rowWasMissing())->toBeTrue()
        ->and($missing->before)->toBeNull()
        ->and($missing->after)->toBe($after)
        ->and($missing->workDateIso())->toBe('2026-03-14')
        ->and($missing->eventName())->toBe('attendance.daily_totals_reconciled')
        ->and($missing->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-03-15T03:50:00+00:00');
})->group('RN-06', 'RL-04', 'RF-PR-02');

it('no dice que faltaba la fila cuando la habia, aunque estuviera a cero', function (): void {
    // El caso que un `empty()` o un «esta vacio» habria confundido: una fila
    // escrita con todo a cero SI existia, y el asiento tiene que poder decirlo.
    $zeroed = new DailyTotalsSnapshot(0, 0, null, null, false, false);

    $reconciled = new DailyTotalsReconciled(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        workDate: WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        divergentFields: ['total_minutes', 'shift_count'],
        before: $zeroed,
        after: new DailyTotalsSnapshot(480, 1, null, null, false, false),
        reconciledAt: new DateTimeImmutable('2026-03-15 03:50:00', new DateTimeZone('UTC')),
    );

    expect($reconciled->rowWasMissing())->toBeFalse()
        ->and($reconciled->before)->toBe($zeroed)
        ->and($reconciled->divergentFields)->toBe(['total_minutes', 'shift_count']);
})->group('RN-06', 'RL-04', 'RF-PR-02');
