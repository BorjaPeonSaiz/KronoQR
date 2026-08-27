<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\InvalidDebounceWindow;
use App\Modules\Attendance\Domain\Policy\DebouncePolicy;
use Tests\Support\Time\Instants;

/*
 * RF-AT-06 — el periodo de gracia anti-rebote.
 *
 * La regla que impide que quien pasa la tarjeta dos veces por costumbre cierre
 * el turno que acaba de abrir y se vaya con una jornada de cero minutos.
 *
 * Sin base de datos y sin framework: es dominio puro, y por eso los dos
 * instantes se escriben a mano en lugar de calcularse.
 */

it('suprime un segundo escaneo dentro de la ventana', function (): void {
    // El escenario «Anti-rebote» del doc 01 §11, literal: «acaba de fichar
    // entrada hace 20 segundos».
    $primero = Instants::utc('2026-03-14 07:02:00');
    $segundo = Instants::utc('2026-03-14 07:02:20');

    $supresor = DebouncePolicy::ofSeconds(60)->suppressorOf($segundo, $primero);

    expect($supresor)->toBe($primero);
})->group('RF-AT-06');

it('deja pasar un escaneo justo en el limite de la ventana', function (): void {
    // Umbral ESTRICTO, la misma semantica `[inicio, fin)` con la que el dominio
    // trata todos sus intervalos. A los 60 s exactos la gracia ya paso.
    $primero = Instants::utc('2026-03-14 07:02:00');

    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppresses(Instants::utc('2026-03-14 07:02:59'), $primero))->toBeTrue()
        ->and($politica->suppresses(Instants::utc('2026-03-14 07:03:00'), $primero))->toBeFalse();
})->group('RF-AT-06');

it('mide la ventana en valor absoluto, tambien hacia atras', function (): void {
    // Regla dura 9: la cola offline puede sincronizar un escaneo cuyo
    // `occurred_at` es ANTERIOR al de otro ya registrado. Con una comparacion
    // con signo, cualquier escaneo del pasado caeria dentro de la ventana y se
    // suprimiria el historico entero de un lote atrasado.
    $yaRegistrado = Instants::utc('2026-03-14 07:02:00');
    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppresses(Instants::utc('2026-03-14 07:01:40'), $yaRegistrado))->toBeTrue()
        ->and($politica->suppresses(Instants::utc('2026-03-14 06:00:00'), $yaRegistrado))->toBeFalse();
})->group('RF-AT-06', 'RF-AT-09');

it('elige el escaneo aceptado mas cercano entre los candidatos', function (): void {
    // Quien decide es el que de verdad esta al lado: es el instante que el
    // quiosco enseña como `last_accepted_at` para poder decir «hace unos
    // segundos» sin inventarselo.
    $lejano = Instants::utc('2026-03-14 07:01:15');
    $cercano = Instants::utc('2026-03-14 07:02:10');

    $supresor = DebouncePolicy::ofSeconds(60)
        ->suppressorOf(Instants::utc('2026-03-14 07:02:00'), $lejano, $cercano);

    expect($supresor)->toBe($cercano);
})->group('RF-AT-06', 'RF-AT-05');

it('no suprime nada cuando no hay escaneos aceptados cerca', function (): void {
    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppressorOf(Instants::utc('2026-03-14 07:02:00')))->toBeNull()
        ->and($politica->suppresses(
            Instants::utc('2026-03-14 07:02:00'),
            Instants::utc('2026-03-14 06:00:00'),
        ))->toBeFalse();
})->group('RF-AT-06');

it('se puede desactivar poniendo la ventana a cero', function (): void {
    // Regla dura 14 y ADR-017: el umbral es configuracion del hotel, y cero es
    // un valor legitimo —no un error—, igual que en `OperationalSettings`.
    $politica = DebouncePolicy::disabled();

    expect($politica->isDisabled())->toBeTrue()
        ->and($politica->suppresses(
            Instants::utc('2026-03-14 07:02:01'),
            Instants::utc('2026-03-14 07:02:00'),
        ))->toBeFalse();
})->group('RF-AT-06');

it('acepta la ventana que sirva la configuracion, no una constante', function (): void {
    // Un hotel con la ventana en 5 minutos y otro con 10 segundos se atienden
    // con el mismo binario (ADR-017, regla dura 13).
    $primero = Instants::utc('2026-03-14 07:02:00');
    $doceSegundosDespues = Instants::utc('2026-03-14 07:02:12');

    expect(DebouncePolicy::ofSeconds(300)->suppresses($doceSegundosDespues, $primero))->toBeTrue()
        ->and(DebouncePolicy::ofSeconds(10)->suppresses($doceSegundosDespues, $primero))->toBeFalse();
})->group('RF-AT-06');

it('rechaza una ventana negativa al construirse', function (): void {
    // Al construir y no al evaluar: el fallo aparece al arrancar con esa
    // configuracion, no en el primer fichaje del turno de noche.
    expect(fn (): DebouncePolicy => DebouncePolicy::ofSeconds(-1))
        ->toThrow(InvalidDebounceWindow::class);
})->group('RF-AT-06');

it('exige que los dos instantes esten en UTC', function (): void {
    // Regla dura 3 y RN-04. Comparar un instante con desplazamiento contra otro
    // sin el desplazaria la ventana una hora entera dos veces al ano.
    $madrid = new DateTimeImmutable('2026-03-14 07:02:00', Instants::madrid());

    expect(fn (): ?DateTimeImmutable => DebouncePolicy::ofSeconds(60)->suppressorOf($madrid))
        ->toThrow(InstantIsNotUtc::class)
        ->and(fn (): ?DateTimeImmutable => DebouncePolicy::ofSeconds(60)->suppressorOf(
            Instants::utc('2026-03-14 07:02:00'),
            $madrid,
        ))->toThrow(InstantIsNotUtc::class);
})->group('RF-AT-06', 'RN-04');
