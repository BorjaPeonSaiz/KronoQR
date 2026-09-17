<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\InvalidDebounceWindow;
use App\Modules\Attendance\Domain\Policy\DebouncePolicy;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
use Tests\Support\Time\Instants;

/*
 * RF-AT-06 — el periodo de gracia anti-rebote.
 *
 * La regla que impide que quien pasa la tarjeta dos veces por costumbre cierre
 * el turno que acaba de abrir y se vaya con una jornada de cero minutos.
 *
 * **Y desde la tarea 3.5, la regla que no puede tragarse una vuelta de pausa**
 * (ADR-024): un `break_end` a veinte segundos de un `break_start` mal pulsado no
 * es un rebote, es alguien corrigiendo su propio error. `ATTENDANCE_DEBOUNCE_SECONDS`
 * se queda en 60 s; lo que cambia es que la politica mira tambien la intencion.
 *
 * Sin base de datos y sin framework: es dominio puro, y por eso los instantes se
 * escriben a mano en lugar de calcularse.
 */

/** Un escaneo ya aceptado del mismo empleado, con lo que hizo con la jornada. */
function accepted(string $at, ClockingAction $action = ClockingAction::CLOCK_IN): AcceptedScan
{
    return new AcceptedScan(Instants::utc($at), $action, 'shift-entry-1');
}

it('suprime un segundo escaneo dentro de la ventana', function (): void {
    // El escenario «Anti-rebote» del doc 01 §11, literal: «acaba de fichar
    // entrada hace 20 segundos».
    $primero = accepted('2026-03-14 07:02:00');
    $segundo = Instants::utc('2026-03-14 07:02:20');

    $supresor = DebouncePolicy::ofSeconds(60)->suppressorOf($segundo, DeclaredIntent::AUTO, $primero);

    expect($supresor)->toBe($primero);
})->group('RF-AT-06');

it('deja pasar un escaneo justo en el limite de la ventana', function (): void {
    // Umbral ESTRICTO, la misma semantica `[inicio, fin)` con la que el dominio
    // trata todos sus intervalos. A los 60 s exactos la gracia ya paso.
    $primero = accepted('2026-03-14 07:02:00');

    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppresses(Instants::utc('2026-03-14 07:02:59'), DeclaredIntent::AUTO, $primero))->toBeTrue()
        ->and($politica->suppresses(Instants::utc('2026-03-14 07:03:00'), DeclaredIntent::AUTO, $primero))->toBeFalse();
})->group('RF-AT-06');

it('mide la ventana en valor absoluto, tambien hacia atras', function (): void {
    // Regla dura 9: la cola offline puede sincronizar un escaneo cuyo
    // `occurred_at` es ANTERIOR al de otro ya registrado. Con una comparacion
    // con signo, cualquier escaneo del pasado caeria dentro de la ventana y se
    // suprimiria el historico entero de un lote atrasado.
    $yaRegistrado = accepted('2026-03-14 07:02:00');
    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppresses(Instants::utc('2026-03-14 07:01:40'), DeclaredIntent::AUTO, $yaRegistrado))->toBeTrue()
        ->and($politica->suppresses(Instants::utc('2026-03-14 06:00:00'), DeclaredIntent::AUTO, $yaRegistrado))->toBeFalse();
})->group('RF-AT-06', 'RF-AT-09');

it('elige el escaneo aceptado mas cercano entre los candidatos', function (): void {
    // Quien decide es el que de verdad esta al lado: es el instante que el
    // quiosco enseña como `last_accepted_at` para poder decir «hace unos
    // segundos» sin inventarselo.
    $lejano = accepted('2026-03-14 07:01:15');
    $cercano = accepted('2026-03-14 07:02:10');

    $supresor = DebouncePolicy::ofSeconds(60)
        ->suppressorOf(Instants::utc('2026-03-14 07:02:00'), DeclaredIntent::AUTO, $lejano, $cercano);

    expect($supresor)->toBe($cercano);
})->group('RF-AT-06', 'RF-AT-05');

it('sigue mirando los candidatos que vienen detras de uno fuera de la ventana', function (): void {
    // Los dos adyacentes llegan del puerto **en orden de instante**, no de
    // distancia: el anterior puede estar lejisimos y el posterior al lado. Quien
    // se parara en el primero que no entra en la ventana dejaria de ver al que
    // de verdad decide, y el doble escaneo accidental de RF-AT-06 pasaria.
    $fuera = accepted('2026-03-14 07:00:30');
    $dentro = accepted('2026-03-14 07:02:10');

    $supresor = DebouncePolicy::ofSeconds(60)
        ->suppressorOf(Instants::utc('2026-03-14 07:02:00'), DeclaredIntent::AUTO, $fuera, $dentro);

    expect($supresor)->toBe($dentro);
})->group('RF-AT-06');

it('desempata a favor del escaneo anterior cuando los dos estan a la misma distancia', function (): void {
    // Un escaneo con un aceptado 30 s antes y otro 30 s despues —un lote offline
    // sincronizado en medio (regla dura 9)— tiene dos vecinos igual de cerca. El
    // aviso del quiosco enseña `last_accepted_at`, asi que la eleccion no puede
    // ser arbitraria: gana el **anterior**, que es el fichaje que la persona ya
    // vio ocurrir.
    $antes = accepted('2026-03-14 07:01:30');
    $despues = accepted('2026-03-14 07:02:30');

    $supresor = DebouncePolicy::ofSeconds(60)
        ->suppressorOf(Instants::utc('2026-03-14 07:02:00'), DeclaredIntent::AUTO, $antes, $despues);

    expect($supresor)->toBe($antes);
})->group('RF-AT-06', 'RF-AT-09');

it('no suprime nada cuando no hay escaneos aceptados cerca', function (): void {
    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppressorOf(Instants::utc('2026-03-14 07:02:00'), DeclaredIntent::AUTO))->toBeNull()
        ->and($politica->suppresses(
            Instants::utc('2026-03-14 07:02:00'),
            DeclaredIntent::AUTO,
            accepted('2026-03-14 06:00:00'),
        ))->toBeFalse();
})->group('RF-AT-06');

it('se puede desactivar poniendo la ventana a cero', function (): void {
    // Regla dura 14 y ADR-017: el umbral es configuracion del hotel, y cero es
    // un valor legitimo —no un error—, igual que en `OperationalSettings`.
    $politica = DebouncePolicy::disabled();

    expect($politica->isDisabled())->toBeTrue()
        ->and($politica->suppresses(
            Instants::utc('2026-03-14 07:02:01'),
            DeclaredIntent::AUTO,
            accepted('2026-03-14 07:02:00'),
        ))->toBeFalse();
})->group('RF-AT-06');

it('acepta la ventana que sirva la configuracion, no una constante', function (): void {
    // Un hotel con la ventana en 5 minutos y otro con 10 segundos se atienden
    // con el mismo binario (ADR-017, regla dura 13).
    $primero = accepted('2026-03-14 07:02:00');
    $doceSegundosDespues = Instants::utc('2026-03-14 07:02:12');

    expect(DebouncePolicy::ofSeconds(300)->suppresses($doceSegundosDespues, DeclaredIntent::AUTO, $primero))->toBeTrue()
        ->and(DebouncePolicy::ofSeconds(10)->suppresses($doceSegundosDespues, DeclaredIntent::AUTO, $primero))->toBeFalse();
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

    expect(fn (): ?AcceptedScan => DebouncePolicy::ofSeconds(60)->suppressorOf($madrid, DeclaredIntent::AUTO))
        ->toThrow(InstantIsNotUtc::class)
        // El aceptado se valida en su propio constructor: un `AcceptedScan` con
        // desplazamiento no llega siquiera a la politica.
        ->and(fn (): AcceptedScan => new AcceptedScan($madrid, ClockingAction::CLOCK_IN))
        ->toThrow(InstantIsNotUtc::class);
})->group('RF-AT-06', 'RN-04');

// --- ADR-024: la intencion entra en la ventana -------------------------------

it('no se traga una vuelta de pausa fichada a los veinte segundos', function (): void {
    /*
     * **El caso que ADR-024 exige resolver.** Alguien pulsa «Pausa» sin querer,
     * se da cuenta y vuelve a pasar la tarjeta: con la ventana intacta, el
     * `break_end` caeria en `rejected_debounce` y el tramo siguiente no se
     * abriria hasta que alguien lo corrigiera por RN-13.
     *
     * Se pierden veinte segundos de tramo, no las cuatro horas que el tramo
     * cerrado deja de contar. «Si hay duda, se registra» (regla dura 19).
     */
    $pausa = accepted('2026-03-14 14:00:00', ClockingAction::BREAK_START);

    $supresor = DebouncePolicy::ofSeconds(60)->suppressorOf(
        Instants::utc('2026-03-14 14:00:20'),
        DeclaredIntent::BREAK_END,
        $pausa,
    );

    expect($supresor)->toBeNull();
})->group('RF-AT-06', 'RF-AT-12');

it('no se traga tampoco una pausa fichada justo tras una vuelta', function (): void {
    // La simetrica: quien vuelve sin querer y pulsa «Pausa» acto seguido. Si
    // solo se protegiera un sentido, el error seria irreparable en el otro.
    $vuelta = accepted('2026-03-14 14:30:00', ClockingAction::BREAK_END);

    expect(DebouncePolicy::ofSeconds(60)->suppresses(
        Instants::utc('2026-03-14 14:30:20'),
        DeclaredIntent::BREAK_START,
        $vuelta,
    ))->toBeFalse();
})->group('RF-AT-06', 'RF-AT-12');

it('sigue suprimiendo dos pausas seguidas a veinte segundos', function (): void {
    // **Repetir no es deshacer.** Dos `break_start` seguidos son el doble
    // escaneo accidental de siempre, y ahi RF-AT-06 sigue mandando: si la
    // excepcion cubriera cualquier intencion explicita, bastaria con armar
    // «Pausa» para desactivar el anti-rebote.
    $pausa = accepted('2026-03-14 14:00:00', ClockingAction::BREAK_START);

    $supresor = DebouncePolicy::ofSeconds(60)->suppressorOf(
        Instants::utc('2026-03-14 14:00:20'),
        DeclaredIntent::BREAK_START,
        $pausa,
    );

    expect($supresor)->toBe($pausa);
})->group('RF-AT-06', 'RF-AT-12');

it('sigue suprimiendo el escaneo sin intencion declarada tras una pausa', function (): void {
    // `auto` no deshace nada: sin declaracion explicita, dos lecturas seguidas
    // de la misma tarjeta siguen siendo un solo gesto. Es lo que mantiene
    // intacto el camino de casi todo el mundo (decision 5 de la ficha 3.5).
    $pausa = accepted('2026-03-14 14:00:00', ClockingAction::BREAK_START);

    expect(DebouncePolicy::ofSeconds(60)->suppresses(
        Instants::utc('2026-03-14 14:00:20'),
        DeclaredIntent::AUTO,
        $pausa,
    ))->toBeTrue();
})->group('RF-AT-06', 'RF-AT-12');

it('respeta los limites de la ventana tambien con una intencion que no deshace', function (): void {
    // Los mismos 59 s / 60 s de siempre, ahora con intencion declarada: la
    // excepcion de ADR-024 no mueve el umbral, solo exime a un caso concreto.
    $pausa = accepted('2026-03-14 14:00:00', ClockingAction::BREAK_START);
    $politica = DebouncePolicy::ofSeconds(60);

    expect($politica->suppresses(Instants::utc('2026-03-14 14:00:59'), DeclaredIntent::BREAK_START, $pausa))->toBeTrue()
        ->and($politica->suppresses(Instants::utc('2026-03-14 14:01:00'), DeclaredIntent::BREAK_START, $pausa))->toBeFalse();
})->group('RF-AT-06', 'RF-AT-12');

it('no exime una vuelta de pausa que llega despues de la ventana', function (): void {
    // Fuera de la ventana no hay nada que eximir: el escaneo se procesa porque
    // esta lejos, no porque deshaga. La distincion importa para no leer la
    // excepcion como «las pausas no tienen anti-rebote».
    $pausa = accepted('2026-03-14 14:00:00', ClockingAction::BREAK_START);

    expect(DebouncePolicy::ofSeconds(60)->suppressorOf(
        Instants::utc('2026-03-14 14:30:00'),
        DeclaredIntent::BREAK_END,
        $pausa,
    ))->toBeNull();
})->group('RF-AT-06', 'RF-AT-12');

it('decide con el vecino mas cercano, no con cualquiera de la ventana', function (): void {
    // Si el que esta al lado es el que se esta deshaciendo, no hay rebote que
    // descartar aunque mas atras haya otro escaneo dentro de la ventana:
    // suprimir aqui seria descartar el escaneo por culpa de un vecino que no es
    // el que la persona acaba de hacer.
    $entradaLejana = accepted('2026-03-14 13:59:30', ClockingAction::CLOCK_IN);
    $pausaCercana = accepted('2026-03-14 14:00:00', ClockingAction::BREAK_START);

    expect(DebouncePolicy::ofSeconds(60)->suppressorOf(
        Instants::utc('2026-03-14 14:00:10'),
        DeclaredIntent::BREAK_END,
        $entradaLejana,
        $pausaCercana,
    ))->toBeNull();
})->group('RF-AT-06', 'RF-AT-12');
