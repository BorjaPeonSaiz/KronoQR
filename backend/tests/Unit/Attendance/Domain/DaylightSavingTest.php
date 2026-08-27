<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-09: la duracion se mide sobre instantes UTC, asi que es inmune al cambio
 * de hora. Los dos cambios de `Europe/Madrid` y en los dos sentidos.
 *
 * En 2026 los dos ultimos domingos son el 29 de marzo —los relojes saltan de
 * 02:00 CET a 03:00 CEST, la noche dura una hora menos— y el 25 de octubre
 * —de 03:00 CEST a 02:00 CET, la noche dura una hora mas—.
 *
 * **La duracion esperada se escribe como numero y se contrasta contra el
 * intervalo UTC real.** Ninguna prueba la deriva restando horas locales: esa
 * resta es justo el error que estas pruebas existen para detectar, y cada caso
 * lleva escrito al lado lo que daria.
 */

it('mide 150 minutos entre las 01:30 CEST y las 03:00 CET del cambio de octubre', function (): void {
    // El escenario literal del doc 01 §11. La aritmetica de horas locales daria
    // 90 minutos y le robaria una hora de nomina a la persona.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-25')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-24 23:30'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::utc('2026-10-25 02:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(150);
})->group('RN-09', 'RQ-02', 'RQ-01');

it('situa las 01:30 CEST y las 03:00 CET del 25 de octubre donde el escenario dice', function (): void {
    // La prueba anterior habla en UTC porque es lo que recibe el dominio; esta
    // fija que esos dos instantes UTC son las horas locales del escenario, para
    // que la equivalencia no quede en un comentario.
    expect(Instants::inMadrid('2026-10-25 01:30')->format(DateTimeImmutable::ATOM))->toBe('2026-10-24T23:30:00+00:00')
        ->and(Instants::inMadrid('2026-10-25 03:00')->format(DateTimeImmutable::ATOM))->toBe('2026-10-25T02:00:00+00:00');
})->group('RN-09', 'RQ-02');

it('mide 90 minutos entre las 01:30 CET y las 04:00 CEST del cambio de marzo', function (): void {
    // El salto hacia delante, el sentido contrario: la aritmetica de horas
    // locales daria 150 minutos y pagaria una hora que nadie trabajo.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-29')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-29 00:30'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::utc('2026-03-29 02:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(90);
})->group('RN-09', 'RQ-02');

it('situa las 01:30 CET y las 04:00 CEST del 29 de marzo donde el escenario dice', function (): void {
    expect(Instants::inMadrid('2026-03-29 01:30')->format(DateTimeImmutable::ATOM))->toBe('2026-03-29T00:30:00+00:00')
        ->and(Instants::inMadrid('2026-03-29 04:00')->format(DateTimeImmutable::ATOM))->toBe('2026-03-29T02:00:00+00:00');
})->group('RN-09', 'RQ-02');

it('mide 540 minutos en el turno de noche que atraviesa el retraso de octubre', function (): void {
    // 22:00 -> 06:00 en el reloj de la pared, pero esa noche dura nueve horas.
    // La persona trabajo 540 minutos y eso es lo que se le paga.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-24')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-24 20:00'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::utc('2026-10-25 05:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(540)
        ->and($workDay->shiftCount())->toBe(1)
        ->and($entry->workDate()->isoDate)->toBe('2026-10-24');
})->group('RN-09', 'RN-05', 'RF-AT-08');

it('mide 420 minutos en el turno de noche que atraviesa el adelanto de marzo', function (): void {
    // El mismo 22:00 -> 06:00 de la pared, siete horas de reloj real.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-28')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-28 21:00'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::utc('2026-03-29 04:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(420)
        ->and($workDay->shiftCount())->toBe(1)
        ->and($entry->workDate()->isoDate)->toBe('2026-03-28');
})->group('RN-09', 'RN-05', 'RF-AT-08');

it('situa el turno de 22:00 a 06:00 de cada cambio en los instantes UTC del escenario', function (): void {
    expect(Instants::inMadrid('2026-10-24 22:00')->format(DateTimeImmutable::ATOM))->toBe('2026-10-24T20:00:00+00:00')
        ->and(Instants::inMadrid('2026-10-25 06:00')->format(DateTimeImmutable::ATOM))->toBe('2026-10-25T05:00:00+00:00')
        ->and(Instants::inMadrid('2026-03-28 22:00')->format(DateTimeImmutable::ATOM))->toBe('2026-03-28T21:00:00+00:00')
        ->and(Instants::inMadrid('2026-03-29 06:00')->format(DateTimeImmutable::ATOM))->toBe('2026-03-29T04:00:00+00:00');
})->group('RN-09', 'RQ-02');

it('mide la hora repetida del 25 de octubre como dos horas distintas', function (): void {
    // Las 02:30 locales ocurren dos veces esa noche. Un turno que entra en la
    // primera y sale en la segunda dura 60 minutos, no cero.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-25')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-25 00:30'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::utc('2026-10-25 01:30'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(60);
})->group('RN-09', 'RQ-02');

it('atribuye a la misma jornada las dos pasadas por las 02:30 del 25 de octubre', function (): void {
    // Las dos son el 25 de octubre en Madrid, con una hora de diferencia real.
    $first = Instants::utc('2026-10-25 00:30');
    $second = Instants::utc('2026-10-25 01:30');

    expect(Instants::asMadridWallClock($first))->toBe('2026-10-25 02:30')
        ->and(Instants::asMadridWallClock($second))->toBe('2026-10-25 02:30')
        ->and((new TimeRange($first, $second))->duration()->minutes)->toBe(60);
})->group('RN-09', 'RN-05');

it('suma el total del dia del cambio de hora sobre instantes y no sobre horas locales', function (): void {
    // Jornada partida la noche del retraso de octubre: 22:00 -> 02:00 y
    // 02:30 -> 06:00 en la pared. Cinco horas la primera —esa madrugada tiene
    // dos veces la una— y tres y media la segunda: 510 minutos.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-24')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-24 20:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-10-25 01:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $workDay->clockIn('shift-entry-2', Instants::utc('2026-10-25 01:30'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-10-25 05:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($workDay->totalWorked()->minutes)->toBe(510)
        ->and($totals->total->minutes)->toBe(510)
        ->and($totals->workDate->isoDate)->toBe('2026-10-24');
})->group('RN-09', 'RN-06', 'RQ-02');

it('no abre una incidencia falsa en el turno de 12 h que atraviesa el adelanto de marzo', function (): void {
    // De 18:00 CET a 07:00 CEST: trece horas de reloj de pared, doce reales. Si
    // la duracion se calculase en local, este turno abriria una incidencia que
    // no toca y alguien tendria que revisarla a mano cada 29 de marzo.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-28')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-28 17:00'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(
        Instants::utc('2026-03-29 05:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(720)
        ->and($workDay->hasAnomaly())->toBeFalse();
})->group('RN-09', 'RN-08');

it('detecta el tramo anomalo que el reloj de pared esconde en el retraso de octubre', function (): void {
    // De 19:00 CEST a 06:01 CET: once horas y un minuto en la pared, doce horas
    // y un minuto reales. En local pasaria desapercibido.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-24')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-24 17:00'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(
        Instants::utc('2026-10-25 05:01'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(721)
        ->and($workDay->hasAnomaly())->toBeTrue();
})->group('RN-09', 'RN-08');
