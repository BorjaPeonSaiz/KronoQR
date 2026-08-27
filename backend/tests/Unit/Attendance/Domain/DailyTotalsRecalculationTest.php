<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-06 y regla dura 7 — el total del dia **se recalcula**, nunca se
 * incrementa, y el evento que alimenta `daily_totals` transporta el estado
 * COMPLETO de la jornada.
 *
 * Estas pruebas miran el contrato del que depende la proyeccion de la tarea 1.4:
 * `DailyTotalsProjector` escribe lo que recibe en `DailyTotalsRecalculated`, asi
 * que si el evento llevara un delta —o un total parcial— la proyeccion mentiria
 * sin que ninguna prueba de infraestructura pudiera notarlo. La otra mitad —que
 * lo escrito coincide con la suma de los tramos— se comprueba contra PostgreSQL
 * en `tests/Integration/Attendance`.
 *
 * Dominio puro: sin base de datos y sin framework.
 */

it('emite el total completo de la jornada, no un incremento', function (): void {
    // Dos tramos cerrados de 120 minutos cada uno y un tercero que se abre: el
    // evento tiene que decir 240, que es la SUMA de lo vigente, y no «+0».
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 08:00')
        ->withClosedShift('2026-03-14 09:00', '2026-03-14 11:00')
        ->build();

    $jornada->clockIn('entrada-3', Instants::utc('2026-03-14 12:00'), ScanOrigin::QR_KIOSK);

    $proyeccion = RecordedEvents::dailyTotals($jornada->releaseEvents());

    expect($proyeccion->total->minutes)->toBe(240)
        ->and($proyeccion->shiftCount)->toBe(3)
        // Un tramo abierto aporta cero al total legal, pero la jornada sabe que
        // esta abierta: es lo que pinta el panel de presencia en vivo.
        ->and($proyeccion->hasOpenShift)->toBeTrue()
        // La ultima salida es la del segundo tramo, no la del que sigue
        // abierto: `last_out_at` describe lo que ya termino, y `has_open_shift`
        // es lo que dice que la jornada continua. Son dos datos y no uno porque
        // el panel en vivo necesita los dos a la vez (RF-PA-01).
        ->and($proyeccion->lastClockOutAt?->format('Y-m-d H:i'))->toBe('2026-03-14 11:00');
})->group('RN-06', 'RF-AT-04');

it('recalcula el total al cerrar el tramo, sumando toda la jornada', function (): void {
    // El escenario «Cierre de turno con acumulado» del doc 01 §11: un tramo
    // previo de 120 minutos y uno que se cierra con 240 dan 360, y el quiosco
    // enseña «Hoy: 6 h 0 min».
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 04:00', '2026-03-14 06:00')
        ->withOpenShiftSince('2026-03-14 07:02')
        ->build();

    $jornada->clockOut(
        Instants::utc('2026-03-14 11:02'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    );

    $proyeccion = RecordedEvents::dailyTotals($jornada->releaseEvents());

    expect($proyeccion->total->minutes)->toBe(360)
        ->and($proyeccion->shiftCount)->toBe(2)
        ->and($proyeccion->hasOpenShift)->toBeFalse()
        ->and($proyeccion->hasAnomaly)->toBeFalse();
})->group('RN-06', 'RF-AT-03');

it('proyecta un total que puede bajar, no un acumulado', function (): void {
    // El caso que un acumulador no puede resolver.
    //
    // Una jornada de la que un tramo ha dejado de ser vigente —anulado o
    // sustituido (ADR-026, RN-13)— **no lo carga**: el agregado se rehidrata
    // solo con lo que cuenta. El evento que sale de ahi lleva el total de esa
    // jornada mas pequena, asi que la proyeccion baja sola. Con `total_minutes
    // = total_minutes + delta` habria que acordarse de restar, y el fallo se
    // descubriria en la nomina de alguien.
    $antes = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 04:00', '2026-03-14 09:00')
        ->withOpenShiftSince('2026-03-14 10:00')
        ->build();

    $antes->clockOut(
        Instants::utc('2026-03-14 12:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    );

    // 5 h + 2 h.
    expect(RecordedEvents::dailyTotals($antes->releaseEvents())->total->minutes)->toBe(420);

    // La misma jornada despues de que el tramo de cinco horas quede anulado: el
    // repositorio ya no lo carga, y el siguiente fichaje proyecta 120 + 0.
    $despues = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 10:00', '2026-03-14 12:00')
        ->build();

    $despues->clockIn('entrada-nueva', Instants::utc('2026-03-14 13:00'), ScanOrigin::QR_KIOSK);

    expect(RecordedEvents::dailyTotals($despues->releaseEvents())->total->minutes)->toBe(120);
})->group('RN-06', 'RN-13');

it('atribuye la proyeccion a la jornada del turno, no al dia natural', function (): void {
    // RN-05 y regla dura 4: un turno 22:00 -> 06:00 proyecta sobre el dia 14,
    // aunque se cierre el 15. Si la proyeccion usara la fecha de la salida,
    // el turno de noche apareceria repartido entre dos dias en la nomina.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 21:00')
        ->build();

    $jornada->clockOut(
        Instants::utc('2026-03-15 05:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    );

    $proyeccion = RecordedEvents::dailyTotals($jornada->releaseEvents());

    expect($proyeccion->workDate->isoDate)->toBe('2026-03-14')
        ->and($proyeccion->total->minutes)->toBe(480);
})->group('RN-06', 'RN-05', 'RF-AT-08');

it('marca la jornada para revision cuando algun tramo quedo anomalo', function (): void {
    // RN-08. `has_incident` de `daily_totals` sale de aqui, y sale del estado de
    // la jornada entera: basta un tramo marcado para que el dia lo este.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    $jornada->clockOut(
        Instants::utc('2026-03-14 19:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    $proyeccion = RecordedEvents::dailyTotals($jornada->releaseEvents());

    expect($proyeccion->hasAnomaly)->toBeTrue()
        // Y el tramo se registra igual, con sus marcas reales: la anomalia
        // marca para revision humana, no deshace lo que el empleado ficho.
        ->and($proyeccion->total->minutes)->toBe(780);
})->group('RN-06', 'RN-08');
