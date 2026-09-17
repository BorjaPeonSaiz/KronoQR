<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\BreakEndWithoutShiftEntry;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Policy\ScanIntentPolicy;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\ClockingResolution;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use Tests\Support\Time\Instants;

/*
 * RF-AT-12 y ADR-024 — que hace este escaneo con la jornada.
 *
 * La pausa y el fin de turno son **estructuralmente identicos**: los dos cierran
 * el tramo abierto. El servidor no puede deducir cual es, asi que la persona lo
 * declara y esta politica lo resuelve con cuatro hechos: la intencion, si hay
 * tramo abierto, cuando ocurre el escaneo y cual fue el ultimo escaneo aceptado.
 *
 * Sin base de datos, sin framework y **sin reloj** (regla dura 2): aqui no se
 * pregunta nada sobre el presente. Los dos instantes son hechos del escaneo
 * —`occurred_at`, regla dura 9— y se escriben a mano, que es lo que permite
 * medir una pausa de doce horas sin esperar doce horas.
 *
 * Todas las filas de la tabla del docblock de la politica tienen prueba, el
 * techo de RN-10 con sus limites, y ese es el punto: una fila mal resuelta no da
 * un error, da una nomina mal pagada.
 */

/**
 * El ultimo escaneo aceptado de la persona, con lo que hizo y de que tramo.
 *
 * Ocurre siempre a las 12:00; el escaneo que llega se escribe a la hora que el
 * caso necesite, de modo que la distancia entre los dos se lee en la prueba.
 */
function lastScan(ClockingAction $action, ?string $shiftEntryUuid = 'shift-entry-1', string $at = '2026-03-14 12:00:00'): AcceptedScan
{
    return new AcceptedScan(Instants::utc($at), $action, $shiftEntryUuid);
}

/**
 * La politica con el techo de continuacion de pausa que pida el caso.
 *
 * **12 h de serie: el descanso minimo del perfil espanol** (RN-10, art. 34.3
 * ET). No es una constante de la politica —lo recibe ya resuelto desde
 * `CompliancePolicy`, regla dura 14— y por eso las pruebas que van a por el
 * limite lo escriben.
 */
function intentPolicy(int $minimumRestHours = 12): ScanIntentPolicy
{
    return ScanIntentPolicy::allowingBreaksShorterThan(WorkedDuration::ofMinutes($minimumRestHours * 60));
}

/** El instante del escaneo que llega. Distinto del de `lastScan()` para que la distancia se vea. */
function scanAt(string $at = '2026-03-14 12:30:00'): DateTimeImmutable
{
    return Instants::utc($at);
}

// --- Con tramo abierto: solo se puede cerrar, la pregunta es como -------------

it('cierra la jornada sin declarar nada, que es el camino de siempre', function (): void {
    // RF-AT-03. El comportamiento anterior a la tarea 3.5 no cambia para quien
    // no declara nada: pasar la tarjeta con turno abierto es salir.
    $resolution = intentPolicy()->resolve(DeclaredIntent::AUTO, workDayHasOpenEntry: true, scanAt: scanAt());

    expect($resolution->action)->toBe(ClockingAction::CLOCK_OUT)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-03', 'RF-AT-12', 'RQ-01');

it('empieza la pausa cuando se declara y hay tramo abierto', function (): void {
    // La fila que estrena RF-AT-12: cierra el tramo **sin** cerrar la jornada.
    // Estructuralmente es un `clockOut`, y por eso el ADR-024 insiste en que lo
    // que los distingue es el motivo del escaneo, no la estructura.
    $resolution = intentPolicy()->resolve(DeclaredIntent::BREAK_START, workDayHasOpenEntry: true, scanAt: scanAt());

    expect($resolution->action)->toBe(ClockingAction::BREAK_START)
        ->and($resolution->closesEntry())->toBeTrue()
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-12', 'RN-05', 'RQ-01');

it('cierra el tramo, sin inventar una pausa, cuando se declara vuelta con la jornada abierta', function (): void {
    // Intencion que CONTRADICE el estado: alguien pulso «vuelvo» con el turno
    // abierto. Se resuelve por la estructura —lo unico que se puede hacer con un
    // tramo abierto es cerrarlo— y **no se inventa una pausa que nadie declaro**:
    // convertirlo en `BREAK_START` anadiria al registro legal un descanso que no
    // ocurrio.
    //
    // No se rechaza (regla dura 19), no se marca y no abre incidencia: `intent`
    // guarda lo que se pidio y `result` lo que se hizo (doc 01 §5.5).
    $resolution = intentPolicy()->resolve(DeclaredIntent::BREAK_END, workDayHasOpenEntry: true, scanAt: scanAt());

    expect($resolution->action)->toBe(ClockingAction::CLOCK_OUT);
})->group('RF-AT-12', 'RF-AT-03', 'RQ-01');

it('no mira el ultimo escaneo aceptado mientras haya tramo abierto', function (): void {
    // Con tramo abierto quien decide es el agregado, que es el hecho fuerte.
    // Mirar tambien el historico abriria la puerta a que dos lecturas de la
    // misma base discreparan sobre el mismo escaneo.
    $policy = intentPolicy();

    expect($policy->resolve(DeclaredIntent::AUTO, true, scanAt(), lastScan(ClockingAction::BREAK_START))->action)
        ->toBe(ClockingAction::CLOCK_OUT)
        ->and($policy->resolve(DeclaredIntent::BREAK_START, true, scanAt(), lastScan(ClockingAction::BREAK_END))->action)
        ->toBe(ClockingAction::BREAK_START);
})->group('RF-AT-12', 'RQ-01');

// --- Sin tramo abierto: se abre uno, la pregunta es en que jornada ------------

it('abre jornada a quien llega por la manana', function (): void {
    // RF-AT-02, y el caso de quien no ha fichado nunca: sin escaneo anterior no
    // hay pausa que continuar.
    $resolution = intentPolicy()->resolve(DeclaredIntent::AUTO, workDayHasOpenEntry: false, scanAt: scanAt());

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->opensEntry())->toBeTrue()
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-02', 'RF-AT-12', 'RQ-01');

it('resuelve la vuelta de pausa sin que nadie pulse nada', function (): void {
    // **La decision que hace que la vuelta no anade ningun paso** (decision 5 de
    // la ficha 3.5): quien vuelve del descanso solo pasa la tarjeta. Si `auto`
    // abriera jornada nueva, el turno de noche quedaria partido en dos dias en
    // cuanto alguien saliera a cenar a las 02:00.
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::AUTO,
        workDayHasOpenEntry: false,
        scanAt: scanAt(),
        lastAccepted: lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe(ClockingAction::BREAK_END)
        ->and($resolution->continuesWorkDayOf())->toBe('shift-entry-7');
})->group('RF-AT-12', 'RN-05', 'RQ-01');

it('resuelve la vuelta de pausa tambien cuando se declara', function (): void {
    // La misma resolucion con `break_end` explicito. Que las dos coincidan es lo
    // que hace que declarar la vuelta sea opcional y no un camino distinto.
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::BREAK_END,
        workDayHasOpenEntry: false,
        scanAt: scanAt(),
        lastAccepted: lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe(ClockingAction::BREAK_END)
        ->and($resolution->continuesWorkDayOf())->toBe('shift-entry-7');
})->group('RF-AT-12', 'RN-05', 'RQ-01');

it('abre jornada cuando se declara vuelta y no hay pausa que continuar', function (ClockingAction $last): void {
    // La otra fila «contradice el estado»: se declara vuelta pero el ultimo
    // escaneo no fue una pausa. Se abre jornada —es lo unico que se puede hacer—
    // y no se rechaza (regla dura 19).
    $resolution = intentPolicy()->resolve(DeclaredIntent::BREAK_END, false, scanAt(), lastScan($last));

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->with([
    'tras una salida' => [ClockingAction::CLOCK_OUT],
    'tras una entrada' => [ClockingAction::CLOCK_IN],
    'tras otra vuelta de pausa' => [ClockingAction::BREAK_END],
])->group('RF-AT-12', 'RF-AT-02', 'RQ-01');

it('abre jornada a quien declara pausa sin tener nada abierto', function (): void {
    // Tercera fila «contradice el estado»: no hay tramo que cerrar, asi que se
    // abre. Y **no se convierte en vuelta de pausa** aunque el ultimo escaneo lo
    // permitiera: declarar «empiezo la pausa» no es declarar «vuelvo de ella», y
    // suponerlo seria el sistema decidiendo por la persona.
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::BREAK_START,
        false,
        scanAt(),
        lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-12', 'RQ-01');

it('abre jornada si de la pausa anterior no consta el tramo', function (): void {
    // Hoy no lo produce nada —todo escaneo aceptado escribe su `shift_entry_id`—
    // pero un dato importado podria no tenerlo, y hay una respuesta correcta que
    // dar: sin saber a que jornada volver, la unica forma de nombrarla seria la
    // fecha civil del escaneo, que es justo lo que parte el turno de noche
    // (ADR-024). Se abre jornada en lugar de reventar el camino de fichaje
    // (regla dura 19).
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::BREAK_END,
        false,
        scanAt(),
        lastScan(ClockingAction::BREAK_START, shiftEntryUuid: null),
    );

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-12', 'RN-05', 'RQ-01');

// --- La resolucion no admite estados imposibles ------------------------------

it('no deja construir una vuelta de pausa sin la jornada que continua', function (): void {
    // RN-05 y ADR-024: un `BREAK_END` sin tramo del que venir no es una decision
    // completa. Que lo impida el tipo —y no un `if` en el caso de uso— es lo que
    // hace imposible acabar buscando la jornada por la fecha del escaneo.
    expect(fn (): ClockingResolution => ClockingResolution::breakEnd(''))
        ->toThrow(BreakEndWithoutShiftEntry::class)
        // Y tampoco con un identificador que solo son espacios: una jornada no se
        // puede buscar por eso, y dejarlo pasar cambiaria el fallo ruidoso de
        // aqui por un `findWorkDayOfShiftEntry()` que no encuentra nada y abre
        // jornada nueva en silencio, que es como se parte un turno de noche.
        ->and(fn (): ClockingResolution => ClockingResolution::breakEnd('   '))
        ->toThrow(BreakEndWithoutShiftEntry::class);
})->group('RF-AT-12', 'RN-05', 'RQ-01');

it('distingue las cuatro acciones por lo que hacen con el tramo', function (): void {
    // El vocabulario que consume el caso de uso: `opensEntry()` traduce a
    // `WorkDay::clockIn()` y `closesEntry()` a `WorkDay::clockOut()`. Que no haya
    // ninguna accion que no haga una de las dos cosas es exactamente la razon
    // por la que el servidor no puede deducir la intencion (ADR-024).
    expect(ClockingAction::CLOCK_IN->opensEntry())->toBeTrue()
        ->and(ClockingAction::BREAK_END->opensEntry())->toBeTrue()
        ->and(ClockingAction::CLOCK_OUT->closesEntry())->toBeTrue()
        ->and(ClockingAction::BREAK_START->closesEntry())->toBeTrue()
        ->and(ClockingAction::BREAK_START->isBreak())->toBeTrue()
        ->and(ClockingAction::BREAK_END->isBreak())->toBeTrue()
        ->and(ClockingAction::CLOCK_IN->isBreak())->toBeFalse()
        ->and(ClockingAction::CLOCK_OUT->isBreak())->toBeFalse()
        // Solo la vuelta de pausa continua una jornada ya abierta: es la mitad
        // de ADR-024 que evita partir el turno de noche.
        ->and(ClockingAction::BREAK_END->continuesOpenWorkDay())->toBeTrue()
        ->and(ClockingAction::CLOCK_IN->continuesOpenWorkDay())->toBeFalse();
})->group('RF-AT-12', 'RN-05', 'RQ-01');

it('conserva los valores de la columna y del contrato', function (): void {
    // Un alfabeto y no dos: `scan_events.result`, el enum `action` de la
    // respuesta y `scan_events.intent` usan estas mismas cadenas. Con dos
    // vocabularios, el CHECK de PostgreSQL seria el unico que notaria la
    // diferencia.
    expect(array_map(fn (ClockingAction $a): string => $a->value, ClockingAction::cases()))
        ->toBe(['clock_in', 'clock_out', 'break_start', 'break_end'])
        ->and(array_map(fn (DeclaredIntent $i): string => $i->value, DeclaredIntent::cases()))
        ->toBe(['auto', 'break_start', 'break_end']);
})->group('RF-AT-12', 'RQ-01');

it('abre jornada con auto tras cualquier escaneo que no dejara una pausa en curso', function (ClockingAction $last): void {
    // La fila 7 de la tabla, que es la de casi todo el mundo: quien llega por la
    // manana lo hace despues de haber salido ayer. Con historial y sin el tiene
    // que resolver lo mismo — si `CLOCK_OUT` o `CLOCK_IN` sirvieran para
    // continuar, cualquier entrada se pegaria a la jornada anterior.
    $resolution = intentPolicy()->resolve(DeclaredIntent::AUTO, false, scanAt(), lastScan($last));

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->with([
    'tras una salida' => [ClockingAction::CLOCK_OUT],
    'tras una entrada' => [ClockingAction::CLOCK_IN],
    'tras una vuelta de pausa' => [ClockingAction::BREAK_END],
])->group('RF-AT-02', 'RF-AT-12', 'RQ-01');

// --- El techo de la pausa es RN-10 -------------------------------------------

it('continua la pausa mientras quepa bajo el descanso minimo entre jornadas', function (string $scanAt, ClockingAction $expected): void {
    /*
     * **El defecto que este techo cierra.** Sin cota, un «Pausa» que nadie
     * continua se come la jornada siguiente: el escaneo de entrada del dia
     * siguiente es `auto`, no hay tramo abierto y el ultimo aceptado es un
     * `break_start`, asi que se resolveria `BREAK_END` y las ocho horas del
     * martes se cargarian a la jornada del lunes, que quedaria con un tramo
     * imposible y el martes a cero. Sin incidencia y sin aviso.
     *
     * El techo no es una constante nueva: es el descanso minimo entre jornadas
     * del perfil (RN-10, art. 34.3 ET, regla dura 14). Si entre la pausa y el
     * escaneo media **menos** que ese descanso, no ha podido ser un descanso
     * entre jornadas y sigue siendo la misma jornada; si media el umbral o mas,
     * por definicion legal ya es otra.
     *
     * Limite **estricto**, como `TimeRange`, RN-02 y `DebouncePolicy`: la pausa
     * empieza a las 12:00 y el descanso minimo es de 12 h.
     */
    $resolution = intentPolicy(minimumRestHours: 12)->resolve(
        DeclaredIntent::AUTO,
        false,
        scanAt($scanAt),
        lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe($expected);
})->with([
    'media hora de comida' => ['2026-03-14 12:30:00', ClockingAction::BREAK_END],
    'once horas y cincuenta y nueve minutos' => ['2026-03-14 23:59:00', ClockingAction::BREAK_END],
    'doce horas exactas, el limite' => ['2026-03-15 00:00:00', ClockingAction::CLOCK_IN],
    'la jornada del dia siguiente' => ['2026-03-15 08:00:00', ClockingAction::CLOCK_IN],
    'una semana despues' => ['2026-03-21 08:00:00', ClockingAction::CLOCK_IN],
])->group('RF-AT-12', 'RN-05', 'RN-10', 'RQ-01');

it('aplica el techo tambien cuando la vuelta se declara explicitamente', function (): void {
    // Declarar `break_end` no salta el techo: pasadas doce horas ya no hay pausa
    // que continuar por mucho que el cliente lo afirme. Es la misma regla de
    // siempre —la intencion se honra cuando la estructura la admite— aplicada al
    // tiempo en lugar de al tramo abierto.
    $resolution = intentPolicy(minimumRestHours: 12)->resolve(
        DeclaredIntent::BREAK_END,
        false,
        scanAt('2026-03-15 00:00:00'),
        lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-12', 'RN-10', 'RQ-01');

it('usa el descanso minimo que fije cada convenio, no un numero propio', function (int $restHours, ClockingAction $expected): void {
    // ADR-017 y regla dura 13: un hotel con un convenio de 10 h y otro con uno
    // de 12 h se atienden con el mismo binario. Una pausa de once horas es pausa
    // en el segundo y ya es otra jornada en el primero, y eso es exactamente lo
    // que dice el art. 34.3 ET de cada uno.
    $resolution = intentPolicy(minimumRestHours: $restHours)->resolve(
        DeclaredIntent::AUTO,
        false,
        scanAt('2026-03-14 23:00:00'),
        lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe($expected);
})->with([
    'convenio de 10 h de descanso' => [10, ClockingAction::CLOCK_IN],
    'perfil espanol de 12 h' => [12, ClockingAction::BREAK_END],
])->group('RF-AT-12', 'RN-10', 'RF-PD-07');

it('no vuelve de una pausa que todavia no habia empezado', function (): void {
    // Regla dura 9 y RF-AT-09: la cola offline puede sincronizar un escaneo cuyo
    // `occurred_at` es ANTERIOR al del ultimo registrado. La distancia se mide en
    // valor absoluto —media hora hacia atras cabe bajo el techo— asi que sin
    // mirar el orden un escaneo del pasado se colgaria de una pausa del futuro,
    // y el tramo abriria antes de que su jornada tuviera pausa ninguna.
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::AUTO,
        false,
        scanAt('2026-03-14 11:30:00'),
        lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe(ClockingAction::CLOCK_IN)
        ->and($resolution->continuesWorkDayOf())->toBeNull();
})->group('RF-AT-12', 'RF-AT-09', 'RQ-01');

it('no depende del estado del tramo del que vuelve, que ni siquiera conoce', function (): void {
    /*
     * La politica decide con el `uuid` que el escaneo dejo escrito y nada mas: no
     * sabe si aquel tramo sigue vigente, si una correccion lo dejo `superseded`
     * (RN-13, ADR-026) o si existe siquiera. Y tiene que ser asi —es dominio puro
     * sin repositorio—, de modo que quien resuelve esa pregunta es
     * `WorkDayRepository::findWorkDayOfAnyShiftEntry()`, que encuentra la jornada
     * de un tramo retirado igual que la de uno vigente.
     *
     * Lo que esta prueba fija es que **el `uuid` viaja intacto**: la politica no
     * lo valida contra nada, no lo normaliza y no lo cambia por la fecha del
     * escaneo. Si el tramo no existiera —purga por retencion (RL-02)— quien
     * llama degrada a `ClockingResolution::clockIn()` sobre la jornada de la
     * fecha y marca el escaneo para revision humana (regla dura 19), y esa
     * decision es del caso de uso, no de aqui.
     */
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::AUTO,
        false,
        scanAt(),
        lastScan(ClockingAction::BREAK_START, '0199aaaa-0000-7000-8000-00000000dead'),
    );

    expect($resolution->continuesWorkDayOf())->toBe('0199aaaa-0000-7000-8000-00000000dead');
})->group('RF-AT-12', 'RN-13', 'RQ-01');

it('continua la pausa cuando el escaneo cae en el mismo instante que la marca', function (): void {
    // El borde del orden: dos marcas en el mismo segundo no estan desordenadas,
    // asi que el escaneo si puede ser la vuelta. De descartar la segunda se
    // encarga el anti-rebote de RF-AT-06, que es quien tiene esa competencia; si
    // esta politica la descartara tambien, un `break_start` y su vuelta con el
    // mismo `occurred_at` —dos tablets con el reloj sincronizado al segundo—
    // abririan jornada nueva y partirian el turno.
    $resolution = intentPolicy()->resolve(
        DeclaredIntent::AUTO,
        false,
        scanAt('2026-03-14 12:00:00'),
        lastScan(ClockingAction::BREAK_START, 'shift-entry-7'),
    );

    expect($resolution->action)->toBe(ClockingAction::BREAK_END)
        ->and($resolution->continuesWorkDayOf())->toBe('shift-entry-7');
})->group('RF-AT-12', 'RF-AT-09', 'RQ-01');

it('exige que el instante del escaneo llegue en UTC', function (): void {
    // Regla dura 3 y RN-04. La distancia que decide si una pausa sigue siendo
    // pausa se mide entre dos instantes: comparar uno con desplazamiento contra
    // otro sin el correria el techo una hora entera dos veces al ano, justo en
    // el cambio de hora, que es cuando el turno de noche mas se complica.
    $madrid = new DateTimeImmutable('2026-03-14 12:30:00', Instants::madrid());

    expect(fn (): ClockingResolution => intentPolicy()->resolve(DeclaredIntent::AUTO, false, $madrid))
        ->toThrow(InstantIsNotUtc::class);
})->group('RF-AT-12', 'RN-04', 'RQ-01');
