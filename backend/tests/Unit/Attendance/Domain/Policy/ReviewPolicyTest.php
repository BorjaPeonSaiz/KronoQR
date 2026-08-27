<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InvalidSkewTolerance;
use App\Modules\Attendance\Domain\Policy\ReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Tests\Support\Time\Instants;

/*
 * RN-15 y RF-AT-11 — que fichajes piden validacion de una persona.
 *
 * RN-15 tiene dos frases y la segunda es esta politica: «el horario de un
 * fichaje offline es el `occurred_at` del dispositivo, marcado con su retraso de
 * sincronizacion. **Si supera el umbral, requiere validacion del responsable**».
 *
 * Sin base de datos y sin framework: es dominio puro (RQ-01).
 */

it('marca el fichaje por PIN cualquiera que sea su desfase', function (): void {
    // RF-AT-11: «misma traza, marcada como origen = PIN y señalada para revision
    // del responsable». El PIN identifica con algo que se sabe, no con algo que
    // se tiene, y es el unico camino donde un compañero puede fichar por otro.
    $politica = ReviewPolicy::toleratingSkewOfMinutes(15);

    expect($politica->requiresReview(ScanOrigin::PIN_KIOSK, ClockSkew::ofSeconds(0)))->toBeTrue();
})->group('RF-AT-11', 'RN-15', 'RQ-01');

it('no marca un escaneo de tarjeta que llega en hora', function (): void {
    // El camino normal (RF-AT-01) no ensucia la bandeja: si todo cuadra, no hay
    // nada que validar. Una bandeja donde entra todo es una bandeja que nadie
    // mira.
    $politica = ReviewPolicy::toleratingSkewOfMinutes(15);

    expect($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(3)))->toBeFalse();
})->group('RF-AT-11', 'RN-15', 'RQ-01');

it('marca el escaneo cuyo retraso supera el umbral de la instalacion', function (): void {
    // El escenario que motiva la regla: un tramo retrodatado a un mes ya
    // exportado a la Inspeccion entra en el registro legal igual que un fichaje
    // normal. Entra —regla dura 19, nunca se rechaza— pero **marcado**.
    $politica = ReviewPolicy::toleratingSkewOfMinutes(15);

    expect($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(11 * 3600)))->toBeTrue();
})->group('RN-15', 'RQ-01');

it('marca tambien el reloj adelantado, no solo el atrasado', function (): void {
    // La magnitud, no el signo: un `occurred_at` en el futuro del servidor es
    // igual de poco fiable que uno en el pasado, y es justo el lado que elegiria
    // quien quisiera postdatar una salida.
    $politica = ReviewPolicy::toleratingSkewOfMinutes(15);

    expect($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(-40 * 60)))->toBeTrue();
})->group('RN-15', 'RQ-01');

it('trata el umbral como limite estricto', function (): void {
    // Misma semantica de limite abierto que `DebouncePolicy` y que la
    // restriccion de exclusion de RN-02: a los 900 s exactos todavia no se
    // marca, a los 901 si.
    $politica = ReviewPolicy::toleratingSkewOfMinutes(15);

    expect($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(900)))->toBeFalse()
        ->and($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(901)))->toBeTrue();
})->group('RN-15', 'RQ-01');

it('respeta el umbral que fije cada instalacion', function (): void {
    // Regla dura 14 y ADR-017: el numero es configuracion, no una constante del
    // codigo. Un hotel con una VLAN inestable puede tolerar mas retraso; otro
    // con quioscos siempre en linea, mucho menos.
    $desfase = ClockSkew::ofSeconds(20 * 60);

    expect(ReviewPolicy::toleratingSkewOfMinutes(60)->requiresReview(ScanOrigin::QR_KIOSK, $desfase))->toBeFalse()
        ->and(ReviewPolicy::toleratingSkewOfMinutes(5)->requiresReview(ScanOrigin::QR_KIOSK, $desfase))->toBeTrue();
})->group('RN-15', 'RQ-01');

it('admite una tolerancia de cero, que pide validar todo lo que no llegue al instante', function (): void {
    // Configuracion extrema pero coherente, al contrario que una negativa.
    $politica = ReviewPolicy::toleratingSkewOfMinutes(0);

    expect($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(0)))->toBeFalse()
        ->and($politica->requiresReview(ScanOrigin::QR_KIOSK, ClockSkew::ofSeconds(1)))->toBeTrue();
})->group('RN-15', 'RQ-01');

it('rechaza una tolerancia negativa al construirse', function (): void {
    // Al construir la politica y no al evaluarla: el fallo tiene que aparecer al
    // arrancar con esa configuracion, no en el primer fichaje del turno de
    // noche.
    expect(fn (): ReviewPolicy => ReviewPolicy::toleratingSkewOfMinutes(-1))
        ->toThrow(InvalidSkewTolerance::class);
})->group('RN-15', 'RQ-01');

it('mide el desfase entre las dos marcas de tiempo del escaneo', function (): void {
    // RF-AT-09 y regla dura 9: `occurred_at` es del dispositivo y `recorded_at`
    // del servidor. Once horas de cola offline son once horas de desfase, y esa
    // es la medida que la politica compara con el umbral.
    $desfase = ClockSkew::between(
        Instants::utc('2026-03-14 08:00:00'),
        Instants::utc('2026-03-14 19:00:00'),
    );

    expect(ReviewPolicy::toleratingSkewOfMinutes(15)->requiresReview(ScanOrigin::QR_KIOSK, $desfase))
        ->toBeTrue();
})->group('RF-AT-09', 'RN-15', 'RQ-01');
