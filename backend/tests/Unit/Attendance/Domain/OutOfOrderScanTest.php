<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ScanIsNotOutOfOrder;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Policy\DebouncePolicy;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
use App\Modules\Attendance\Domain\ValueObject\OutOfOrderScan;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-18: el **fichaje irreconciliable**.
 *
 * Un escaneo que no puede encajar en la jornada no produce tramo, y no puede
 * producirlo nunca: repetirlo mil veces da el mismo resultado. Toma dos formas,
 * una por cada camino del fichaje, y las dos vienen del mismo sitio —un lote de
 * la cola offline que llega desordenado (RF-AT-09, RN-15)—:
 *
 * - **Al cerrar**, el escaneo no es posterior a la entrada del turno abierto.
 *   Lo prohibe RN-03, que exige salida ESTRICTAMENTE posterior.
 * - **Al abrir**, el tramo que se crearia pisaria a uno ya cerrado y vigente.
 *   Lo prohibe RN-02, con la semantica `[inicio, fin)` de la restriccion de
 *   exclusion.
 *
 * Antes de esta regla las dos salian del agregado como excepcion, el caso de uso
 * las trataba como carrera y la cola del quiosco las reintentaba
 * indefinidamente, con lo que **el escaneo no llegaba a dejar ni una linea** en
 * el registro (regla dura 19 incumplida por no registrar).
 *
 * La decision vive aqui, en la jornada, y es una **resolucion**: el agregado
 * responde «esto no cuadra» sin lanzar y sin tocar nada. Las dos excepciones se
 * quedan donde estaban, como ultima defensa para quien fiche sin preguntar
 * antes — que es justo lo que estas pruebas comprueban que sigue pasando.
 *
 * Sin base de datos y sin framework: son instantes que entran y una decision
 * que sale.
 */

// ---------------------------------------------------------------------------
// Camino de cierre: el escaneo no es posterior a la entrada del turno abierto
// ---------------------------------------------------------------------------

it('reconoce una salida anterior a la entrada del turno abierto', function (): void {
    // El caso que destapo la prueba de carga: un lote de la cola offline trae
    // una salida con `occurred_at` diez minutos ANTES de la entrada que ya
    // estaba registrada.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();

    $outOfOrder = $workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-14 13:50'));

    expect($outOfOrder)->toBeInstanceOf(OutOfOrderScan::class)
        ->and($outOfOrder?->occurredAt)->toEqual(Instants::utc('2026-03-14 13:50'))
        ->and($outOfOrder?->openedAt)->toEqual(Instants::utc('2026-03-14 14:00'))
        ->and($outOfOrder?->closedAt)->toBeNull();
})->group('RN-18', 'RF-AT-07', 'RN-01');

it('no toca la jornada al resolver que un escaneo es irreconciliable', function (): void {
    // «Sin tramo»: el tramo abierto sigue abierto, no aparece ninguno nuevo y no
    // se emite ningun evento de dominio. Lo que se registre despues en
    // `scan_events` es cosa del caso de uso, no del agregado.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();

    $workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-14 13:50'));

    expect($workDay->shiftCount())->toBe(1)
        ->and($workDay->hasOpenEntry())->toBeTrue()
        ->and($workDay->openEntry()?->clockedInAt())->toEqual(Instants::utc('2026-03-14 14:00'))
        ->and($workDay->totalWorked()->minutes)->toBe(0)
        ->and($workDay->releaseEvents())->toBe([]);
})->group('RN-18', 'RN-06');

it('deja que una salida posterior a la entrada cierre el tramo con normalidad', function (): void {
    // El limite por el otro lado: un segundo despues de la entrada ya cuadra, y
    // el camino de fichaje sigue siendo el de siempre.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-14 14:00:01')))->toBeNull();
})->group('RN-18', 'RF-AT-03');

it('trata como irreconciliable el escaneo que coincide al segundo con la entrada', function (): void {
    // LIMITE EXACTO del camino de cierre, y la decision esta escrita en el doc
    // 01 §4: `occurred_at` igual a la entrada tampoco produce tramo. Un tramo de
    // duracion cero no es representable —RN-03 exige salida ESTRICTAMENTE
    // posterior— asi que admitirlo obligaria a cambiar el invariante para salvar
    // un borde. Tratarlo como fuera de orden le da el mismo desenlace honesto:
    // se registra, se revisa y lo arregla una persona.
    //
    // El limite del camino de apertura se comporta al reves, y por otra regla:
    // ver mas abajo.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-14 14:00')))
        ->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RN-03');

it('sigue rechazando por RN-03 a quien cierra el tramo sin preguntar antes', function (): void {
    // La resolucion no sustituye a la invariante: la anticipa. `TimeRange` es la
    // ultima defensa y no se relaja, de modo que un camino futuro que se salte
    // la pregunta falla en vez de escribir un tramo imposible.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();

    expect(fn (): ShiftEntry => $workDay->clockOut(
        Instants::utc('2026-03-14 14:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    ))->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-18', 'RN-03');

it('resuelve igual un inicio de pausa fuera de orden', function (): void {
    // ADR-024: `break_start` y `clock_out` son estructuralmente identicos —los
    // dos cierran el tramo abierto— asi que caen en el mismo camino. Lo que la
    // resolucion mira es si la accion abre o cierra, nunca si es una pausa.
    $workDay = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 21:00')
        ->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::BREAK_START, Instants::utc('2026-03-14 20:50')))
        ->toBeInstanceOf(OutOfOrderScan::class)
        ->and(fn (): ShiftEntry => $workDay->clockOut(
            Instants::utc('2026-03-14 20:50'),
            ScanOrigin::QR_KIOSK,
            ClockingPolicyFactory::standard(),
            ClockingAction::BREAK_START,
        ))->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-18', 'RF-AT-12');

it('no aplica el camino de cierre cuando no hay ningun turno abierto', function (): void {
    // Sin turno abierto no hay entrada que contradecir. El caso de uso no llega
    // aqui —`ScanIntentPolicy` decide abrir cuando no hay tramo abierto— pero la
    // respuesta tiene que ser la misma se pregunte cuando se pregunte.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-14 05:00')))->toBeNull();
})->group('RN-18', 'RN-01');

// ---------------------------------------------------------------------------
// Camino de apertura: el tramo que se abriria pisaria a uno ya cerrado
// ---------------------------------------------------------------------------

it('reconoce una entrada que pisaria a un tramo ya cerrado', function (): void {
    // El hermano del caso anterior, y llega por el mismo sitio: la tablet A se
    // queda sin red y encola la entrada de las 08:00; la persona ficha entera su
    // jornada en la tablet B, de 09:00 a 13:00; cuando A recupera la red, su
    // entrada de las 08:00 abriria un tramo sin fin que pisa al de B.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    $outOfOrder = $workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-14 08:00'));

    expect($outOfOrder)->toBeInstanceOf(OutOfOrderScan::class)
        ->and($outOfOrder?->occurredAt)->toEqual(Instants::utc('2026-03-14 08:00'))
        ->and($outOfOrder?->openedAt)->toEqual(Instants::utc('2026-03-14 09:00'))
        ->and($outOfOrder?->closedAt)->toEqual(Instants::utc('2026-03-14 13:00'));
})->group('RN-18', 'RF-AT-07', 'RN-02');

it('no toca la jornada al resolver que una entrada es irreconciliable', function (): void {
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    $workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-14 08:00'));

    expect($workDay->shiftCount())->toBe(1)
        ->and($workDay->hasOpenEntry())->toBeFalse()
        ->and($workDay->totalWorked()->minutes)->toBe(240)
        ->and($workDay->releaseEvents())->toBe([]);
})->group('RN-18', 'RN-06');

it('reconoce una entrada que cae dentro de un tramo ya cerrado', function (): void {
    // No hace falta que la entrada sea anterior al tramo: basta con que el tramo
    // siga vivo despues de ella. El tramo nuevo no tiene fin, asi que pisa todo
    // lo que termine mas tarde (RN-02).
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-14 11:00')))
        ->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RN-02');

it('deja abrir tramo justo en la salida del tramo cerrado anterior', function (): void {
    // LIMITE EXACTO del camino de apertura, y se comporta al REVES que el de
    // cierre porque lo gobierna otra regla: RN-02 con intervalos `[inicio, fin)`.
    // Un tramo que sale a las 13:00 y otro que entra a las 13:00 no se solapan
    // —asi lo dice `TimeRange::overlaps()` y asi opera la restriccion de
    // exclusion de PostgreSQL— y aqui no hay ninguna duracion cero que crear:
    // el tramo nuevo empieza y sigue abierto.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-14 13:00')))->toBeNull();
})->group('RN-18', 'RN-02');

it('deja abrir tramo despues de un tramo cerrado entero', function (): void {
    // La jornada partida normal: se trabajo de 10:00 a 12:00 y se vuelve a
    // entrar a las 13:50. Nada que resolver.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 10:00', '2026-03-14 12:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-14 13:50')))->toBeNull();
})->group('RN-18', 'RF-AT-02');

it('resuelve igual una vuelta de pausa que pisaria a un tramo cerrado', function (): void {
    // ADR-024: `break_end` y `clock_in` tambien son estructuralmente identicos
    // —los dos abren tramo— y comparten camino. La vuelta de una pausa que llega
    // tarde de la cola offline no puede colarse dentro de un tramo ya cerrado.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::BREAK_END, Instants::utc('2026-03-14 08:00')))
        ->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RF-AT-12');

it('deja el tramo abierto fuera del camino de apertura, porque eso es una carrera', function (): void {
    // DELIBERADO. Un tramo ABIERTO en la jornada cargada, en el camino de
    // apertura, no describe un fichaje irreconciliable: describe la carrera de
    // diez personas pasando la tarjeta a la vez en el cambio de turno. Esa la
    // resuelve RN-01 —`ShiftAlreadyOpen`, reintento, y en el segundo intento el
    // perdedor ve el tramo del ganador y sale por el anti-rebote (RF-AT-06)—, y
    // convertirla en RN-18 dejaria sin fichar a quien llego segundo.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 09:00')->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-14 08:00')))->toBeNull();
})->group('RN-18', 'RN-01');

it('sigue rechazando por RN-02 a quien abre el tramo sin preguntar antes', function (): void {
    // La otra ultima defensa, intacta: `guardNothingExtendsBeyond()` sigue
    // lanzando `OverlappingShiftEntry`, de modo que un camino futuro que se
    // salte la pregunta falla en vez de escribir dos tramos solapados.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    expect(fn (): ShiftEntry => $workDay->clockIn(
        'shift-entry-tardio',
        Instants::utc('2026-03-14 08:00'),
        ScanOrigin::QR_KIOSK,
    ))->toThrow(OverlappingShiftEntry::class);
})->group('RN-18', 'RN-02');

// ---------------------------------------------------------------------------
// Medianoche y cambios de hora: se comparan instantes, nunca horas de reloj
// ---------------------------------------------------------------------------

it('no confunde un turno de noche legitimo con un fichaje irreconciliable', function (): void {
    // Entrada el dia 14 a las 22:00 de Madrid y salida a las 06:00: la hora del
    // reloj es MENOR y aun asi el fichaje cuadra, porque lo que se compara son
    // instantes y no horas locales (RN-05, regla dura 4, ADR-006).
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();
    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::inMadrid('2026-03-15 06:00')))->toBeNull();
})->group('RN-18', 'RN-05', 'RF-AT-08');

it('reconoce como irreconciliable la salida de la manana anterior al turno de noche', function (): void {
    // La misma hora de reloj —06:00— pero del dia en que el turno todavia no
    // habia empezado. Es el caso que produce un lote offline desordenado, y el
    // que distingue comparar instantes de comparar horas del dia.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();
    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::inMadrid('2026-03-14 06:00')))
        ->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RN-05', 'RF-AT-08');

it('no inventa un fichaje irreconciliable en el cambio de hora de otono', function (): void {
    // RN-09. La madrugada del 25 de octubre las 02:15 ocurren DESPUES de las
    // 02:30: el reloj de Madrid retrocede a las 03:00 CEST. Comparando horas
    // locales, esa salida pareceria anterior a su entrada y se registraria como
    // irreconciliable un turno de 45 minutos perfectamente normal.
    //
    // Los dos instantes se escriben en UTC porque en hora local son ambiguos.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-25')->build();
    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-25 00:30'), ScanOrigin::QR_KIOSK);

    $entry = $workDay->clockOut(
        Instants::utc('2026-10-25 01:15'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    );

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-10-25 01:15')))->toBeNull()
        ->and($entry->workedDuration()->minutes)->toBe(45);
})->group('RN-18', 'RN-09');

it('reconoce el fichaje irreconciliable tambien en el cambio de hora de otono', function (): void {
    // El reverso: 00:15 UTC son las 02:15 CEST, quince minutos ANTES de la
    // entrada. La hora local es la misma que la de la prueba anterior y el
    // veredicto es el contrario, que es lo que demuestra que no se mira.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-10-25')->build();
    $workDay->clockIn('shift-entry-1', Instants::utc('2026-10-25 00:30'), ScanOrigin::QR_KIOSK);

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-10-25 00:15')))
        ->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RN-09');

it('no inventa un fichaje irreconciliable en el cambio de hora de marzo', function (): void {
    // RN-09 por el otro salto. La madrugada del 29 de marzo el reloj de Madrid
    // salta de las 02:00 CET a las 03:00 CEST: entre la entrada (01:30 local) y
    // la salida (03:15 local) el reloj de la pared dice 1 h 45 min y la persona
    // trabajo 45. Un calculo sobre horas locales daria la duracion equivocada;
    // uno sobre el HUSO, si mezclara CET y CEST, podria incluso dar el veredicto
    // contrario.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-29')->build();
    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-29 00:30'), ScanOrigin::QR_KIOSK);

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-29 01:15'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    );

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-29 01:15')))->toBeNull()
        ->and($entry->workedDuration()->minutes)->toBe(45)
        ->and(Instants::asMadridWallClock(Instants::utc('2026-03-29 00:30')))->toBe('2026-03-29 01:30')
        ->and(Instants::asMadridWallClock(Instants::utc('2026-03-29 01:15')))->toBe('2026-03-29 03:15');
})->group('RN-18', 'RN-09');

it('reconoce el fichaje irreconciliable tambien en el cambio de hora de marzo', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-29')->build();
    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-29 00:30'), ScanOrigin::QR_KIOSK);

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, Instants::utc('2026-03-29 00:15')))
        ->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RN-09');

it('reconoce en el cambio de hora de marzo la entrada que pisa a un tramo cerrado', function (): void {
    // El camino de apertura en el mismo salto: el tramo cerrado va de 01:00 CET
    // a 03:30 CEST y la entrada que llega tarde marca las 03:00 CEST, que caen
    // DENTRO. Las cifras del reloj no lo dicen; los instantes si.
    $workDay = WorkDayFactory::new()
        ->onWorkDate('2026-03-29')
        ->withClosedShift('2026-03-29 00:00', '2026-03-29 01:30')
        ->reconstituted();

    expect($workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-29 01:00')))
        ->toBeInstanceOf(OutOfOrderScan::class)
        ->and($workDay->outOfOrderScanFor(ClockingAction::CLOCK_IN, Instants::utc('2026-03-29 01:30')))->toBeNull();
})->group('RN-18', 'RN-09', 'RN-02');

// ---------------------------------------------------------------------------
// El objeto de valor y el orden frente al anti-rebote
// ---------------------------------------------------------------------------

it('exige UTC al preguntar por un fichaje irreconciliable', function (): void {
    // Regla dura 3 y RN-04: el dominio no INTERPRETA un instante con
    // desplazamiento, lo rechaza antes de compararlo. Las 15:30 de Madrid son
    // las 14:30 UTC y el veredicto seria «cuadra»; quien se olvido de convertir
    // recibe un error en vez de una respuesta calculada sobre una marca que
    // nadie declaro en UTC — que es la unica forma de que el olvido se vea.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();

    expect(fn (): ?OutOfOrderScan => $workDay->outOfOrderScanFor(
        ClockingAction::CLOCK_OUT,
        new DateTimeImmutable('2026-03-14 15:30', Instants::madrid()),
    ))->toThrow(InstantIsNotUtc::class);
})->group('RN-18', 'RN-04');

it('exige UTC tambien en el camino de apertura', function (): void {
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 09:00', '2026-03-14 13:00')->reconstituted();

    expect(fn (): ?OutOfOrderScan => $workDay->outOfOrderScanFor(
        ClockingAction::CLOCK_IN,
        new DateTimeImmutable('2026-03-14 15:30', Instants::madrid()),
    ))->toThrow(InstantIsNotUtc::class);
})->group('RN-18', 'RN-04');

it('no deja construir un fichaje irreconciliable que si cuadra al cerrar', function (): void {
    // Regla 5 del diseno: el estado imposible no se construye. Este objeto de
    // valor SOLO puede existir cuando el escaneo de verdad no cuadra, asi que
    // quien lo recibe no tiene que volver a comprobarlo.
    expect(fn (): OutOfOrderScan => OutOfOrderScan::beforeOpenEntry(
        Instants::utc('2026-03-14 14:00'),
        Instants::utc('2026-03-14 14:01'),
    ))->toThrow(ScanIsNotOutOfOrder::class);
})->group('RN-18');

it('no deja construir un fichaje irreconciliable que si cuadra al abrir', function (): void {
    expect(fn (): OutOfOrderScan => OutOfOrderScan::overlappingClosedEntry(
        new TimeRange(Instants::utc('2026-03-14 09:00'), Instants::utc('2026-03-14 13:00')),
        Instants::utc('2026-03-14 13:00'),
    ))->toThrow(ScanIsNotOutOfOrder::class);
})->group('RN-18');

it('exige UTC en los dos instantes del fichaje irreconciliable', function (): void {
    expect(fn (): OutOfOrderScan => OutOfOrderScan::beforeOpenEntry(
        new DateTimeImmutable('2026-03-14 14:00', Instants::madrid()),
        Instants::utc('2026-03-14 13:50'),
    ))->toThrow(InstantIsNotUtc::class)
        ->and(fn (): OutOfOrderScan => OutOfOrderScan::beforeOpenEntry(
            Instants::utc('2026-03-14 14:00'),
            new DateTimeImmutable('2026-03-14 13:50', Instants::madrid()),
        ))->toThrow(InstantIsNotUtc::class)
        ->and(fn (): OutOfOrderScan => OutOfOrderScan::overlappingClosedEntry(
            new TimeRange(Instants::utc('2026-03-14 09:00'), Instants::utc('2026-03-14 13:00')),
            new DateTimeImmutable('2026-03-14 08:00', Instants::madrid()),
        ))->toThrow(InstantIsNotUtc::class);
})->group('RN-18', 'RN-04');

it('deja el anti-rebote por delante de RN-18 cuando los dos podrian opinar', function (): void {
    // RF-AT-06 y ADR-031 mandan sobre RN-18: un reenvio treinta segundos ANTES
    // de la entrada cae dentro de la ventana —se mide en valor absoluto, no con
    // signo— y es un rebote, no un fichaje irreconciliable. El desenlace no es
    // el mismo: el rebote responde `200` con el acumulado del dia y RN-18
    // responde un rechazo generico con incidencia.
    //
    // Donde se garantiza el orden: `RegisterScanHandler::processResolved()`
    // consulta el supresor ANTES de preguntar al agregado. La politica vive
    // fuera de la jornada porque decide sobre los escaneos del empleado, no
    // sobre los tramos de un dia.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();
    $entrada = new AcceptedScan(Instants::utc('2026-03-14 14:00'), ClockingAction::CLOCK_IN, 'shift-entry-1');
    $reenvio = Instants::utc('2026-03-14 13:59:30');

    expect(DebouncePolicy::ofSeconds(60)->suppressorOf($reenvio, DeclaredIntent::AUTO, $entrada))->toBe($entrada)
        ->and($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, $reenvio))->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RF-AT-06');

it('deja decidir a RN-18 cuando el anti-rebote no suprime', function (): void {
    // Con la ventana apagada —ATTENDANCE_DEBOUNCE_SECONDS a cero, que un hotel
    // puede configurar (ADR-017)— no hay nada delante y la resolucion de la
    // jornada es la que responde.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 14:00')->reconstituted();
    $entrada = new AcceptedScan(Instants::utc('2026-03-14 14:00'), ClockingAction::CLOCK_IN, 'shift-entry-1');
    $tardio = Instants::utc('2026-03-14 13:59:30');

    expect(DebouncePolicy::disabled()->suppressorOf($tardio, DeclaredIntent::AUTO, $entrada))->toBeNull()
        ->and($workDay->outOfOrderScanFor(ClockingAction::CLOCK_OUT, $tardio))->toBeInstanceOf(OutOfOrderScan::class);
})->group('RN-18', 'RF-AT-06');
