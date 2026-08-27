<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Time\Instants;

/*
 * RF-AT-09 y RN-15 — la distancia entre las dos marcas de tiempo del escaneo.
 *
 * `occurred_at` lo pone el dispositivo y `recorded_at` el servidor (regla dura
 * 9). Lo que las separa lo produce un reloj desviado (RF-AT-10) o una cola
 * offline (RN-15), y el servidor no puede distinguirlas: solo ve dos instantes.
 */

it('mide once horas de cola offline con signo positivo', function (): void {
    $desfase = ClockSkew::between(
        Instants::utc('2026-03-14 08:00:00'),
        Instants::utc('2026-03-14 19:00:00'),
    );

    expect($desfase->seconds)->toBe(11 * 3600)
        ->and($desfase->magnitudeSeconds())->toBe(11 * 3600)
        ->and($desfase->isAhead())->toBeFalse();
})->group('RF-AT-09', 'RN-15', 'RQ-01');

it('distingue un reloj adelantado por el signo', function (): void {
    // El escenario «Reloj del quiosco desviado» del doc 01 §11: 40 minutos de
    // adelanto. El signo se conserva porque un reloj adelantado y uno atrasado
    // son dos averias distintas para quien las diagnostique.
    $desfase = ClockSkew::between(
        Instants::utc('2026-03-14 07:40:00'),
        Instants::utc('2026-03-14 07:00:00'),
    );

    expect($desfase->seconds)->toBe(-2400)
        ->and($desfase->magnitudeSeconds())->toBe(2400)
        ->and($desfase->isAhead())->toBeTrue();
})->group('RF-AT-09', 'RN-15', 'RQ-01');

it('es cero cuando el escaneo llega en el mismo instante en que ocurre', function (): void {
    $instante = Instants::utc('2026-03-14 07:00:00');

    expect(ClockSkew::between($instante, $instante)->seconds)->toBe(0)
        ->and(ClockSkew::between($instante, $instante)->isAhead())->toBeFalse();
})->group('RF-AT-09', 'RN-15', 'RQ-01');

it('rechaza medir el desfase contra una marca que no esta en UTC', function (): void {
    // Regla dura 3: dos marcas con desplazamientos distintos dan un desfase que
    // solo describe la zona horaria de quien lo calculo.
    $local = new DateTimeImmutable('2026-03-14 08:00:00', new DateTimeZone('Europe/Madrid'));

    expect(fn (): ClockSkew => ClockSkew::between($local, Instants::utc('2026-03-14 08:00:00')))
        ->toThrow(InstantIsNotUtc::class);
})->group('RF-AT-09', 'RQ-01');
