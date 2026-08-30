<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Policy\AnomalyDetectionPolicy;
use App\Modules\Attendance\Domain\Policy\ReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * La deteccion automatica de incidencias (RF-PR-01, tarea 2.6), enunciada como
 * regla de dominio y probada sin base de datos ni framework.
 *
 * Las seis reglas que evalua llegan con **su umbral ya resuelto** (regla dura
 * 14): las operativas —RN-07, RN-08 y RN-15— por `ClockingPolicy` y
 * `ReviewPolicy`, y las legales —RN-10, RN-11 y RN-12— dentro de
 * `CompliancePolicy`. Ninguna prueba de aqui da por sabido un numero: el que va
 * a por un limite lo escribe.
 *
 * **El instante «ahora» se inyecta** (regla dura 2). Sin eso no se puede probar
 * el turno abierto de 12:01 sin esperar doce horas y un minuto.
 *
 * Ninguna de las reglas cierra, corrige ni descarta nada: producen un hallazgo
 * que otro modulo convierte en incidencia para una persona (regla dura 19).
 */

/**
 * El perfil espanol de serie: 12 h de descanso, 9 h de jornada ordinaria y 6 h
 * de tramo continuo (migracion de `compliance_profiles`, perfil `ES-hosteleria`).
 */
function spanishProfile(int $restHours = 12, int $dailyHours = 9, int $breakAfterHours = 6): CompliancePolicy
{
    return new CompliancePolicy(
        minimumRestMinutes: $restHours * 60,
        maximumDailyMinutes: $dailyHours * 60,
        breakRequiredAfterMinutes: $breakAfterHours * 60,
        retentionYears: 4,
    );
}

function detectionPolicy(?CompliancePolicy $legal = null, int $skewToleranceMinutes = 15): AnomalyDetectionPolicy
{
    return new AnomalyDetectionPolicy(
        ClockingPolicyFactory::standard(),
        ReviewPolicy::toleratingSkewOfMinutes($skewToleranceMinutes),
        $legal ?? spanishProfile(),
    );
}

/**
 * @param  list<DetectedAnomaly>  $anomalies
 * @return list<string>
 */
function typesOf(array $anomalies): array
{
    return array_map(static fn (DetectedAnomaly $anomaly): string => $anomaly->type->value, $anomalies);
}

// --- RN-08: el turno abierto que ya dura demasiado ---------------------------

it('detecta el turno abierto por encima del umbral y no antes', function (string $now, array $expected): void {
    // 12 h configuradas (perfil de serie): a las 11:59 y a las 12:00 no hay
    // nada que revisar; a las 12:01 si. El umbral exacto todavia no lo es.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    $found = detectionPolicy()->inspect($day, Instants::utc($now));

    expect(typesOf($found))->toBe($expected);
})->with([
    'once horas y cincuenta y nueve minutos' => ['2026-03-14 17:59', []],
    'doce horas exactas' => ['2026-03-14 18:00', []],
    'doce horas y un minuto' => ['2026-03-14 18:01', ['open_shift_expired']],
    'trece horas, el escenario «Turno olvidado»' => ['2026-03-14 19:00', ['open_shift_expired']],
])->group('RN-08', 'RF-PR-01');

it('describe el turno abierto con los minutos que lleva y el umbral aplicado', function (): void {
    // El contexto es lo que la bandeja ensena y lo que deja constancia del
    // umbral VIGENTE en el momento de la deteccion (RF-PD-07): el mismo tramo
    // con otra configuracion no significa lo mismo. Y no lleva ningun nombre.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    $found = detectionPolicy()->inspect($day, Instants::utc('2026-03-14 19:00'));

    expect($found)->toHaveCount(1)
        ->and($found[0]->type)->toBe(AnomalyType::OPEN_SHIFT_EXPIRED)
        ->and($found[0]->shiftEntryUuid)->toBe('shift-entry-1')
        ->and($found[0]->workDate->isoDate)->toBe('2026-03-14')
        ->and($found[0]->context)->toBe([
            'open_minutes' => 780,
            'threshold_minutes' => 720,
        ]);
})->group('RN-08', 'RF-PR-01');

it('no cierra el tramo abierto al detectarlo', function (): void {
    // RN-08 literal: «nunca se cierra automaticamente sin intervencion humana».
    // La politica clasifica; el tramo sigue exactamente como estaba.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    detectionPolicy()->inspect($day, Instants::utc('2026-03-14 19:00'));

    expect($day->openEntry())->not->toBeNull()
        ->and($day->openEntry()?->isOpen())->toBeTrue()
        ->and($day->openEntry()?->clockedOutAt())->toBeNull();
})->group('RN-08', 'RF-PR-01');

// --- RN-07: el tramo demasiado corto para computar ---------------------------

it('detecta el tramo por debajo de la duracion minima computable', function (string $clockedOutAt, array $expected): void {
    // 1 minuto de serie: 59 s truncan a 0 y son tramo corto; 60 s ya computan.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 08:00:00', $clockedOutAt)
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'))))->toBe($expected);
})->with([
    'cincuenta y nueve segundos' => ['2026-03-14 08:00:59', ['short_shift']],
    'sesenta segundos' => ['2026-03-14 08:01:00', []],
])->group('RN-07');

it('conserva el tramo corto con sus dos marcas', function (): void {
    // RN-07: «por debajo se registra el evento pero se marca como incidencia».
    // Marcar no es borrar: el tramo sigue en la jornada y sigue contando.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 08:00:00', '2026-03-14 08:00:30')
        ->build();

    detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'));

    expect($day->entries())->toHaveCount(1)
        ->and($day->entries()[0]->clockedOutAt())->not->toBeNull();
})->group('RN-07');

// --- RN-10: el descanso entre jornadas ---------------------------------------

it('detecta el descanso insuficiente entre el turno anterior y el siguiente', function (string $clockedInAt, array $expected): void {
    // Perfil espanol: 12 h. A las 11:59 de descanso salta; a las 12:00 exactas
    // no. El umbral llega por `CompliancePolicy`, nunca escrito en el dominio.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift($clockedInAt, '2026-03-17 14:00')
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        Instants::utc('2026-03-16 22:00'),
    );

    expect(typesOf($found))->toBe($expected);
})->with([
    'once horas y cincuenta y nueve minutos de descanso' => ['2026-03-17 09:59', ['insufficient_rest']],
    'doce horas exactas de descanso' => ['2026-03-17 10:00', []],
    'trece horas de descanso' => ['2026-03-17 11:00', []],
])->group('RN-10', 'RF-PD-07');

it('cambia de veredicto con el perfil de cumplimiento, sin tocar el codigo', function (int $restHours, array $expected): void {
    // Escenario «Perfil de cumplimiento distinto» del doc 01 §11: dos turnos
    // separados por 11 h no alertan con un perfil de 10 h y si con el espanol
    // de 12 h. Es la regla dura 14 hecha prueba.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 09:00', '2026-03-17 13:00')
        ->build();

    $found = detectionPolicy(spanishProfile(restHours: $restHours))->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        Instants::utc('2026-03-16 22:00'),
    );

    expect(typesOf($found))->toBe($expected);
})->with([
    'perfil de 10 horas' => [10, []],
    'perfil espanol de 12 horas' => [12, ['insufficient_rest']],
])->group('RN-10', 'RF-PD-07');

it('describe el descanso insuficiente con los minutos reales y el umbral', function (): void {
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 09:59', '2026-03-17 14:00')
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        Instants::utc('2026-03-16 22:00'),
    );

    expect($found[0]->context)->toBe([
        'rest_minutes' => 719,
        'threshold_minutes' => 720,
    ])
        // Se cuelga del tramo que ABRE la jornada, que es el que empezo antes de
        // tiempo: es lo que quien revisa necesita mirar.
        ->and($found[0]->shiftEntryUuid)->toBe('shift-entry-1');
})->group('RN-10', 'RF-PD-07');

it('no evalua el descanso cuando no consta la jornada anterior', function (): void {
    // Sin turno anterior no hay descanso que medir. Inventar uno —suponer que
    // el dia anterior empezo a las 00:00— produciria una alerta sobre alguien
    // que acaba de incorporarse.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 06:00', '2026-03-17 12:00')
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-17 23:00'), null)))->toBe([]);
})->group('RN-10', 'RF-PD-07');

// --- RN-11: la jornada diaria ordinaria --------------------------------------

it('detecta la jornada por encima de la ordinaria sobre la SUMA de los tramos', function (string $secondClockOut, array $expected): void {
    // 9 h de perfil, repartidas en dos tramos: 4 h por la manana y el resto por
    // la tarde. La regla mira el dia entero, no un tramo, que es lo que la
    // distingue de RN-08.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->withClosedShift('2026-03-14 11:00', $secondClockOut)
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'))))->toBe($expected);
})->with([
    'ocho horas y cincuenta y nueve minutos' => ['2026-03-14 15:59', []],
    'nueve horas exactas' => ['2026-03-14 16:00', []],
    'nueve horas y un minuto' => ['2026-03-14 16:01', ['long_shift']],
])->group('RN-11', 'RF-PD-07');

it('mide la jornada sobre los tramos vigentes, que son los unicos que el agregado admite', function (): void {
    // ADR-026: un tramo anulado o sustituido no forma parte de la jornada
    // —`WorkDay::reconstitute()` se niega a rehidratarlo— asi que RN-11 se
    // evalua exactamente sobre lo que quedo vigente. Once horas de tramos
    // retirados no pueden inflar el total de nadie.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 11:00')
        ->build();

    expect($day->totalWorked()->minutes)->toBe(300)
        ->and(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'))))->toBe([]);
})->group('RN-11', 'RF-PD-07');

it('describe la jornada larga sin colgarla de ningun tramo', function (): void {
    // La incidencia es de la JORNADA: ningun tramo por si solo la explica, y
    // colgarla de uno arbitrario haria pensar que ese es el problema.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->withClosedShift('2026-03-14 11:00', '2026-03-14 16:01')
        ->build();

    $found = detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'));

    expect($found)->toHaveCount(1)
        ->and($found[0]->shiftEntryUuid)->toBeNull()
        ->and($found[0]->context)->toBe([
            'worked_minutes' => 541,
            'threshold_minutes' => 540,
        ]);
})->group('RN-11', 'RF-PD-07');

it('no repite la jornada larga cuando un solo tramo ya la explica', function (): void {
    // Un tramo de 13 h dispara RN-08 —que es mas preciso y senala el tramo— y
    // ademas superaria RN-11. Dos incidencias que dicen lo mismo con distinta
    // precision solo hacen ruido en la bandeja de quien las revisa.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 19:00')
        ->build();

    $found = detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'));

    expect(typesOf($found))->toBe(['long_shift', 'missing_break'])
        ->and($found[0]->shiftEntryUuid)->toBe('shift-entry-1');
})->group('RN-11', 'RN-08');

// --- RN-12: el descanso en jornada continuada --------------------------------

it('detecta el tramo continuo por encima del maximo sin pausa', function (string $clockedOutAt, array $expected): void {
    // 6 h de perfil. Con ADR-024 la pausa SON dos tramos, asi que «sin pausa
    // registrada» es exactamente «un solo tramo continuo».
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', $clockedOutAt)
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'))))->toBe($expected);
})->with([
    'cinco horas y cincuenta y nueve minutos' => ['2026-03-14 11:59', []],
    'seis horas exactas' => ['2026-03-14 12:00', []],
    'seis horas y un minuto' => ['2026-03-14 12:01', ['missing_break']],
])->group('RN-12', 'RF-PD-07');

it('no alerta de pausa cuando la jornada son cuatro horas, pausa y cuatro horas', function (): void {
    // El caso negativo del plan: 4 h + pausa + 4 h son DOS tramos, ninguno
    // supera las 6 h, y la suma —8 h— tampoco supera las 9 h de RN-11. Si esto
    // alertara, alertaria media plantilla todos los dias.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 08:00', '2026-03-14 12:00')
        ->withClosedShift('2026-03-14 12:30', '2026-03-14 16:30')
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-14 23:00'))))->toBe([]);
})->group('RN-12', 'RF-PD-07');

it('no evalua la pausa sobre un tramo todavia abierto', function (): void {
    // Mientras el tramo sigue vivo, «lleva 6 h y media» no es un hecho cerrado:
    // la persona puede estar a punto de salir a comer. Lo que si se evalua sobre
    // un tramo abierto es RN-08, y con su propio umbral.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-14 13:00'))))->toBe([]);
})->group('RN-12', 'RF-PD-07');

// --- RN-15: el desfase de reloj ----------------------------------------------

it('pide validacion del responsable por encima de la tolerancia de desfase', function (int $seconds, bool $expected): void {
    // RN-15: «si supera el umbral, requiere validacion del responsable». El
    // limite es estricto: 900 s exactos no, 901 s si. Es la misma comparacion
    // que ya usa `ReviewPolicy` al registrar el escaneo — no una segunda copia.
    expect(detectionPolicy()->skewRequiresValidation(ClockSkew::ofSeconds($seconds)))->toBe($expected);
})->with([
    'catorce minutos y cincuenta y nueve segundos' => [899, false],
    'quince minutos exactos' => [900, false],
    'quince minutos y un segundo' => [901, true],
    'cuarenta minutos de adelanto' => [2400, true],
    'cuarenta minutos de atraso' => [-2400, true],
])->group('RN-15');

// --- RN-05 y RN-09: medianoche y los dos cambios de hora ---------------------

it('mide el turno de noche como un solo tramo del dia en que empezo', function (): void {
    // Regla dura 4 y RN-05: 22:00 -> 06:00 es UN tramo, atribuido al dia de
    // inicio. La deteccion no lo parte a medianoche ni lo reparte entre dos
    // jornadas: el hallazgo lleva la `work_date` del tramo que abrio.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 21:00', '2026-03-15 05:00')
        ->build();

    $found = detectionPolicy()->inspect($day, Instants::utc('2026-03-15 12:00'));

    expect(typesOf($found))->toBe(['missing_break'])
        ->and($found[0]->workDate->isoDate)->toBe('2026-03-14')
        ->and($found[0]->context['worked_minutes'])->toBe(480);
})->group('RN-05', 'RN-09');

it('mide sobre instantes reales las dos noches de cambio de hora', function (string $isoDate, string $localIn, string $localOut, int $minutes, array $expected): void {
    // RN-09. La noche de primavera los relojes saltan de 02:00 a 03:00 y el
    // turno de 8 h de reloj son 7 reales; la de otono las 02:00 ocurren dos
    // veces y son 9. La jornada de otono llega EXACTAMENTE a las 9 h del perfil
    // y por eso no alerta de jornada larga: un umbral evaluado sobre horas de
    // reloj habria dicho 8 h en los dos casos.
    $day = WorkDayFactory::new()
        ->onWorkDate($isoDate)
        ->inTimezone(Instants::MADRID)
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid($localIn),
            Instants::inMadrid($localOut),
        ))
        ->build();

    $found = detectionPolicy()->inspect($day, Instants::utc('2026-11-01 12:00'));

    expect(typesOf($found))->toBe($expected)
        ->and($day->totalWorked()->minutes)->toBe($minutes);
})->with([
    'primavera: 8 h de reloj, 7 reales' => ['2026-03-28', '2026-03-28 23:00', '2026-03-29 07:00', 420, ['missing_break']],
    'otono: 8 h de reloj, 9 reales' => ['2026-10-24', '2026-10-24 23:00', '2026-10-25 07:00', 540, ['missing_break']],
])->group('RN-05', 'RN-09');

// --- Los bordes que no son un umbral ----------------------------------------

it('no inventa una duracion negativa para un tramo abierto por delante del reloj', function (string $now): void {
    // Un fichaje offline puede llegar con `occurred_at` posterior al reloj del
    // servidor (regla dura 9, RF-AT-09) o exactamente igual. Restar ahi daria una
    // duracion negativa, y `TimeRange` la rechazaria: la deteccion no puede
    // reventar por una marca que el propio producto admite.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc($now))))->toBe([]);
})->with([
    'el reloj marca justo la entrada' => ['2026-03-14 06:00'],
    'el reloj va por detras de la entrada' => ['2026-03-14 05:00'],
])->group('RN-08', 'RF-PR-01');

it('no mide un descanso negativo cuando la jornada anterior invade a la siguiente', function (): void {
    // Dos tramos que se pisan son un solape, y de eso responde RN-02 en el
    // esquema. Medir el descanso aqui daria un numero negativo —siempre por
    // debajo del minimo— y convertiria un problema de datos en una alerta legal
    // sobre una persona.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 06:00', '2026-03-17 10:00')
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        // La jornada anterior habria terminado DESPUES de esta entrada.
        Instants::utc('2026-03-17 08:00'),
    );

    expect(typesOf($found))->toBe([]);
})->group('RN-10', 'RF-PD-07');

it('se niega a revisar con un instante que no es UTC', function (): void {
    // Regla dura 3. Un «ahora» en hora local desplazaria una hora la duracion de
    // todos los turnos abiertos dos veces al año, justo en las noches de cambio
    // de hora, que es cuando el calculo importa mas.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 06:00')
        ->build();

    expect(fn (): array => detectionPolicy()->inspect($day, new DateTimeImmutable('2026-03-14 19:00', new DateTimeZone(Instants::MADRID))))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-09');

it('no construye un hallazgo sin empleado ni con un instante que no sea UTC', function (callable $build, string $expected): void {
    // El hallazgo viaja a otro modulo y acaba siendo una fila de `incidents`: si
    // se pudiera construir sin empleado, la incidencia no se podria asignar a
    // nadie, y con un instante local el «tiempo hasta resolver» del doc 01 §9.2
    // saldria desplazado.
    expect($build)->toThrow($expected);
})->with([
    'sin empleado' => [fn (): DetectedAnomaly => new DetectedAnomaly(
        AnomalyType::SHORT_SHIFT,
        '   ',
        1,
        WorkDate::fromIsoDate('2026-03-14', Instants::madrid()),
        null,
        Instants::utc('2026-03-14 19:00'),
    ), InvalidArgumentException::class],
    'instante que no es UTC' => [fn (): DetectedAnomaly => new DetectedAnomaly(
        AnomalyType::SHORT_SHIFT,
        'employee-1',
        1,
        WorkDate::fromIsoDate('2026-03-14', Instants::madrid()),
        null,
        new DateTimeImmutable('2026-03-14 19:00', new DateTimeZone(Instants::MADRID)),
    ), InstantIsNotUtc::class],
])->group('RF-PR-01');

it('cuenta como descanso de cero minutos el turno que empieza justo al terminar el anterior', function (): void {
    // No es un solape —RN-02 usa limites `[inicio, fin)` y dos tramos que se
    // tocan no se pisan— sino el peor descanso que se puede registrar sin
    // solapar. Descartarlo dejaria fuera justo el caso extremo de RN-10.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 06:00', '2026-03-17 10:00')
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        Instants::utc('2026-03-17 06:00'),
    );

    expect(typesOf($found))->toBe(['insufficient_rest'])
        ->and($found[0]->context)->toBe(['rest_minutes' => 0, 'threshold_minutes' => 720]);
})->group('RN-10', 'RF-PD-07');

it('no revisa el descanso de una jornada sin tramos', function (): void {
    // `WorkDay::start()` produce una jornada vacia —el primer fichaje del dia
    // todavia no ha ocurrido— y sin tramos no hay ni descanso que medir ni tramo
    // del que colgar el hallazgo.
    $day = WorkDayFactory::new()->onWorkDate('2026-03-17')->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-17 23:00'), Instants::utc('2026-03-17 06:00'))))
        ->toBe([]);
})->group('RN-10', 'RF-PD-07');

it('no mide el descanso entre dos tramos de la misma jornada, y eso es una limitacion declarada', function (): void {
    // Salir a las 15:00 y volver a entrar a las 23:00 del mismo dia son DOS
    // tramos de la MISMA `work_date` (RN-05): ocho horas de por medio, por debajo
    // de las doce del perfil, y aqui **no salta nada**.
    //
    // Es deliberado y esta escrito en el doc 01 §4: sin la intencion declarada
    // del fichaje (ADR-024, RF-AT-12, tarea 3.5) ese hueco puede ser una pausa
    // para comer o el descanso entre dos turnos, y alertar de todos convertiria
    // cada jornada partida en un incumplimiento del art. 34.3 ET.
    //
    // Esta prueba fija el comportamiento de hoy para que el dia que la 3.5 lo
    // cambie, lo cambie a proposito y no por descuido.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 06:00', '2026-03-17 14:00')
        ->withClosedShift('2026-03-17 22:00', '2026-03-17 23:00')
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-18 12:00'),
        // La jornada anterior termino con descanso de sobra: lo unico corto es el
        // hueco intrajornada.
        Instants::utc('2026-03-16 12:00'),
    );

    expect(typesOf($found))->toBe(['missing_break'])
        ->and($found[0]->context['worked_minutes'])->toBe(480);
})->group('RN-10');

it('cuelga el descanso insuficiente del tramo que abre, llegue en el orden que llegue', function (bool $openingFirst): void {
    // `WorkDay::reconstitute()` no ordena los tramos: conserva el orden en que se
    // los dieron. Si la incidencia se colgara de una posicion —la primera o la
    // ultima—, bastaria con que otro consumidor cargara por `id` para que RN-10
    // señalara el tramo equivocado: el de la tarde en vez del que empezo antes de
    // tiempo. Por eso se prueban los dos ordenes: cualquier implementacion que
    // mire la posicion falla en uno de los dos.
    $opening = ShiftEntryFactory::new()->withUuid('el-que-abre')->worked('2026-03-17 09:59', '2026-03-17 13:00');
    $afternoon = ShiftEntryFactory::new()->withUuid('el-de-la-tarde')->worked('2026-03-17 15:00', '2026-03-17 18:00');

    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withShift($openingFirst ? $opening : $afternoon)
        ->withShift($openingFirst ? $afternoon : $opening)
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        Instants::utc('2026-03-16 22:00'),
    );

    expect(typesOf($found))->toBe(['insufficient_rest'])
        ->and($found[0]->shiftEntryUuid)->toBe('el-que-abre')
        ->and($found[0]->context['rest_minutes'])->toBe(719);
})->with([
    'el que abre llega primero' => [true],
    'el que abre llega el ultimo' => [false],
])->group('RN-10', 'RF-PD-07');

it('no mide descanso cuando la jornada anterior invade a la siguiente por un segundo', function (): void {
    // El limite exacto del solape: un segundo por debajo de cero sigue siendo un
    // solape, no un descanso de cero minutos. Redondearlo a cero produciria la
    // alerta mas grave posible sobre un problema de datos que responde RN-02.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-17')
        ->withClosedShift('2026-03-17 09:59:59', '2026-03-17 13:00:00')
        ->build();

    $found = detectionPolicy()->inspect(
        $day,
        Instants::utc('2026-03-17 23:00'),
        Instants::utc('2026-03-17 10:00:00'),
    );

    expect(typesOf($found))->toBe([]);
})->group('RN-10', 'RF-PD-07');

it('no cuenta el tramo abierto en la jornada de RN-11 hasta que se cierra', function (): void {
    // `totalWorked()` cuenta cero por un tramo abierto: el registro legal suma lo
    // fichado, no lo que va corriendo. Cuatro horas cerradas mas nueve abiertas
    // **no** alertan hoy —alertaran la noche siguiente al cierre—, y lo que ese
    // tramo abierto tiene de raro lo dice RN-08 con su propio umbral.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->withShift(ShiftEntryFactory::new()->openSince('2026-03-14 11:00'))
        ->build();

    // Nueve horas abiertas: por encima de las 9 h de jornada ordinaria y por
    // debajo de las 12 h de tramo anomalo.
    $found = detectionPolicy()->inspect($day, Instants::utc('2026-03-14 20:00'));

    expect(typesOf($found))->toBe([])
        ->and($day->totalWorked()->minutes)->toBe(240);
})->group('RN-11', 'RF-PD-07');

it('alerta de la jornada larga en cuanto ese mismo tramo se cierra', function (): void {
    // La otra mitad de la prueba anterior: lo que cambia no es la regla, es que el
    // dia ya es un hecho.
    $day = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->withClosedShift('2026-03-14 11:00', '2026-03-14 16:01')
        ->build();

    expect(typesOf(detectionPolicy()->inspect($day, Instants::utc('2026-03-15 04:30'))))->toBe(['long_shift']);
})->group('RN-11', 'RF-PD-07');
