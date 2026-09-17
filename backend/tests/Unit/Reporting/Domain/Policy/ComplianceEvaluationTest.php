<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Policy\ComplianceEvaluation;
use App\Modules\Reporting\Domain\ValueObject\ComplianceEmployee;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFinding;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleName;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleStatus;
use App\Modules\Reporting\Domain\ValueObject\ComplianceShiftSegment;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSuspensionReason;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use Tests\Support\Time\Instants;

/*
 * **El evaluador de la vista de cumplimiento** (RF-PA-06), enunciado como regla
 * de dominio y probado sin base de datos, sin framework y **sin reloj**.
 *
 * Sin reloj a proposito: aqui no se pregunta nada sobre el presente. Todo lo que
 * se mide —un descanso, una jornada, un tramo, una semana— ya ocurrio y viene en
 * los hechos (regla dura 2; el instante de generacion es del caso de uso).
 *
 * Los cuatro umbrales llegan dentro de `CompliancePolicy`, resueltos desde el
 * perfil del centro (regla dura 14): ninguna prueba de aqui da por sabido un
 * numero, el que va a por un limite lo escribe.
 *
 * Las comparaciones se prueban en sus limites en
 * `Tests\Unit\Shared\Domain\ValueObject\CompliancePolicyPredicatesTest`, que es
 * donde viven. Lo que se prueba aqui es lo otro: **que se mide, sobre que, y en
 * que orden sale**.
 */

/**
 * El perfil con los umbrales que pida el caso.
 *
 * Nombre propio del fichero: en Pest las funciones de un fichero de prueba son
 * globales para toda la suite.
 */
function evaluationProfile(
    int $restHours = 12,
    int $dailyHours = 9,
    int $breakAfterHours = 6,
    int $weeklyHours = 40,
    int $weekStartsOn = 1,
): CompliancePolicy {
    return new CompliancePolicy(
        minimumRestMinutes: $restHours * 60,
        maximumDailyMinutes: $dailyHours * 60,
        breakRequiredAfterMinutes: $breakAfterHours * 60,
        retentionYears: 4,
        maximumWeeklyMinutes: $weeklyHours * 60,
        weekStartsOn: $weekStartsOn,
        holidayCalendar: [],
    );
}

function evaluationEmployee(string $uuid = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'): ComplianceEmployee
{
    return new ComplianceEmployee(
        uuid: $uuid,
        employeeCode: 'E7QK2MXPR',
        firstName: 'Youssef',
        lastName: 'Amrani',
        departmentId: 3,
        departmentName: 'Cocina',
    );
}

/**
 * Una jornada ya ocurrida. Las horas se escriben en **hora de reloj del centro**,
 * que es como estan enunciados los escenarios del doc 01 §11; la conversion a UTC
 * la hace {@see Instants}, y es justo la que hace que el cambio de hora se note.
 */
function evaluationDay(
    string $workDate,
    ?string $firstIn = null,
    ?string $lastOut = null,
    ?string $previousLastOut = null,
    int $totalMinutes = 0,
    bool $hasOpenShift = false,
    ?string $longestFrom = null,
    ?string $longestTo = null,
    ?ComplianceEmployee $employee = null,
): ComplianceFacts {
    return new ComplianceFacts(
        employee: $employee ?? evaluationEmployee(),
        workDate: $workDate,
        firstInAt: $firstIn === null ? null : Instants::inMadrid($firstIn),
        lastOutAt: $lastOut === null ? null : Instants::inMadrid($lastOut),
        previousLastOutAt: $previousLastOut === null ? null : Instants::inMadrid($previousLastOut),
        totalMinutes: $totalMinutes,
        hasOpenShift: $hasOpenShift,
        longestClosedSegment: $longestFrom === null || $longestTo === null ? null : new ComplianceShiftSegment(
            '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
            Instants::inMadrid($longestFrom),
            Instants::inMadrid($longestTo),
        ),
        openingShiftEntryUuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b10',
    );
}

/**
 * @param  list<ComplianceFinding>  $findings
 * @return list<string>
 */
function ruleNamesOf(array $findings): array
{
    return array_map(static fn (ComplianceFinding $f): string => $f->rule->value, $findings);
}

it('mide el descanso desde el fin real del turno nocturno, sin partirlo a medianoche', function (): void {
    /*
     * RN-05 y ADR-006 (regla dura 4): el 22:00 → 06:00 es UN tramo, atribuido a la
     * jornada del dia 14. La siguiente entrada es a las 18:00 del dia 15.
     *
     * Del fin real —06:00 del dia 15— a las 18:00 del mismo dia van **12 h
     * exactas**, que con el perfil espanol NO alertan. Si el turno se hubiera
     * partido a medianoche, el «fin de la jornada anterior» seria 00:00 y el
     * descanso saldria de 18 h: la regla no saltaria nunca y nadie se enteraria.
     * Y si se midiera contra la entrada, saldrian 20 h.
     */
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [
            evaluationDay('2026-03-14', firstIn: '2026-03-14 22:00', lastOut: '2026-03-15 06:00', totalMinutes: 480),
            evaluationDay('2026-03-15', firstIn: '2026-03-15 18:00', lastOut: '2026-03-15 23:00', previousLastOut: '2026-03-15 06:00', totalMinutes: 300),
        ],
        DateRange::between('2026-03-14', '2026-03-15'),
    );

    expect(ruleNamesOf($findings))->toBe([]);
})->group('RN-05', 'RN-10', 'RF-PA-06');

it('alerta cuando ese mismo descanso se queda en once horas y cincuenta y nueve minutos', function (): void {
    // Un minuto antes, y la regla salta. Es el otro lado del mismo caso: lo que
    // se compara es el hueco real, no el dia de calendario.
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [
            evaluationDay('2026-03-14', firstIn: '2026-03-14 22:00', lastOut: '2026-03-15 06:00', totalMinutes: 480),
            evaluationDay('2026-03-15', firstIn: '2026-03-15 17:59', lastOut: '2026-03-15 23:00', previousLastOut: '2026-03-15 06:00', totalMinutes: 301),
        ],
        DateRange::between('2026-03-14', '2026-03-15'),
    );

    expect(ruleNamesOf($findings))->toBe(['insufficient_rest'])
        ->and($findings[0]->measuredMinutes)->toBe(11 * 60 + 59)
        ->and($findings[0]->thresholdMinutes)->toBe(12 * 60)
        // La diferencia va hecha para que la pantalla no reste: «faltan 1 min».
        ->and($findings[0]->differenceMinutes)->toBe(1)
        // Se cuelga del tramo que ABRE la jornada, que es el que empezo antes de
        // tiempo y el que quien revisa necesita mirar.
        ->and($findings[0]->shiftEntryUuid)->toBe('0199f2c1-8a10-7b40-9c50-6d7e8f9a0b10')
        ->and($findings[0]->workDate)->toBe('2026-03-15')
        ->and($findings[0]->week)->toBeNull();
})->group('RN-10', 'RF-PA-06');

it('no evalua el descanso de la primera jornada de la que consta la anterior', function (): void {
    // Sin jornada anterior no se evalua: suponer que el dia anterior termino a
    // medianoche produciria una alerta de descanso insuficiente sobre alguien que
    // acaba de incorporarse. Aqui la jornada del 14 no la tiene, y no sale.
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [evaluationDay('2026-03-14', firstIn: '2026-03-14 09:00', lastOut: '2026-03-14 17:00', totalMinutes: 480)],
        DateRange::between('2026-03-14', '2026-03-14'),
    );

    expect(ruleNamesOf($findings))->toBe([]);
})->group('RN-10', 'RF-PA-06');

it('no confunde un solape con el peor descanso posible', function (): void {
    // Una jornada que empieza ANTES de que termine la anterior no describe un
    // descanso corto sino un solape, del que responde RN-02 en el esquema.
    // Medirlo aqui daria un descanso negativo, que ademas se leeria como el
    // incumplimiento mas grave que se puede registrar.
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [evaluationDay('2026-03-15', firstIn: '2026-03-15 08:00', lastOut: '2026-03-15 12:00', previousLastOut: '2026-03-15 09:00', totalMinutes: 240)],
        DateRange::between('2026-03-15', '2026-03-15'),
    );

    expect(ruleNamesOf($findings))->toBe([]);
})->group('RN-10', 'RN-02', 'RF-PA-06');

it('el cambio de hora de marzo no regala una hora de descanso ni desplaza la semana', function (): void {
    /*
     * RN-09. El 29 de marzo de 2026 Madrid pasa de las 02:00 a las 03:00.
     *
     * Del sabado 28 a las 23:00 al domingo 29 a las 10:00 el reloj marca once
     * horas, pero **son diez reales**: la hora que el reloj se salta no se
     * descansa. Con 12 h de minimo, alerta y el hueco medido son 600 minutos, no
     * 660. La resta se hace sobre instantes UTC, que es lo que lo garantiza.
     *
     * Y la semana no se mueve: se compone de `work_date`, que son etiquetas de
     * calendario. El domingo del cambio sigue siendo el septimo dia de la semana
     * que empezo el lunes 23.
     */
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [
            evaluationDay('2026-03-28', firstIn: '2026-03-28 15:00', lastOut: '2026-03-28 23:00', totalMinutes: 480),
            evaluationDay('2026-03-29', firstIn: '2026-03-29 10:00', lastOut: '2026-03-29 18:00', previousLastOut: '2026-03-28 23:00', totalMinutes: 480),
        ],
        DateRange::between('2026-03-28', '2026-03-29'),
    );

    expect(ruleNamesOf($findings))->toBe(['insufficient_rest'])
        ->and($findings[0]->measuredMinutes)->toBe(600)
        ->and($findings[0]->differenceMinutes)->toBe(120);
})->group('RN-09', 'RN-10', 'RF-PA-06');

it('el cambio de hora de octubre no roba una hora de descanso', function (): void {
    /*
     * RN-09, el lado contrario al de marzo y el que mas fácil se implementa mal.
     *
     * El 25 de octubre de 2026 Madrid retrasa el reloj de las 03:00 a las 02:00,
     * así que esa madrugada dura una hora **de más**. Del sábado 24 a las 23:00 al
     * domingo 25 a las 10:00 el reloj marca once horas, pero **son doce reales**:
     * la hora que se repite se descansa.
     *
     * Con 12 h de mínimo, eso **no alerta**. Una implementación que restara fechas
     * civiles —o que convirtiera a hora local antes de restar— daría 660 minutos y
     * abriría un aviso de descanso insuficiente contra alguien que descansó las
     * doce horas exactas. La resta sobre instantes UTC es lo que lo evita, y es la
     * misma que en marzo quita una hora.
     */
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [
            evaluationDay('2026-10-24', firstIn: '2026-10-24 15:00', lastOut: '2026-10-24 23:00', totalMinutes: 480),
            evaluationDay('2026-10-25', firstIn: '2026-10-25 10:00', lastOut: '2026-10-25 18:00', previousLastOut: '2026-10-24 23:00', totalMinutes: 480),
        ],
        DateRange::between('2026-10-24', '2026-10-25'),
    );

    expect(ruleNamesOf($findings))->toBe([]);
})->group('RN-09', 'RN-10', 'RF-PA-06');

it('un turno que atraviesa el salto de octubre dura lo que dura de verdad', function (): void {
    /*
     * RN-05 y RN-09 juntas. El turno del sábado 24 a las 22:00 al domingo 25 a las
     * 06:01 **hora de reloj** atraviesa la hora repetida: son 541 minutos reales,
     * no 481. Con la jornada ordinaria en 9 h (540 min), eso es un exceso de UN
     * minuto — y una implementación que midiera con el reloj de pared no vería
     * nada.
     *
     * El tramo es uno solo y pertenece entero a la jornada del 24, la de su hora
     * de inicio (ADR-006, regla dura 4): partirlo a medianoche daría dos jornadas
     * de cuatro horas y media y ningún aviso.
     */
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [evaluationDay(
            '2026-10-24',
            firstIn: '2026-10-24 22:00',
            lastOut: '2026-10-25 06:01',
            totalMinutes: 541,
            longestFrom: '2026-10-24 22:00',
            longestTo: '2026-10-25 06:01',
        )],
        DateRange::between('2026-10-24', '2026-10-24'),
    );

    expect(ruleNamesOf($findings))->toBe(['daily_excess'])
        ->and($findings[0]->measuredMinutes)->toBe(541)
        ->and($findings[0]->thresholdMinutes)->toBe(540)
        ->and($findings[0]->differenceMinutes)->toBe(1)
        ->and($findings[0]->workDate)->toBe('2026-10-24');
})->group('RN-05', 'RN-09', 'RN-11', 'RF-PA-06');

it('suma la semana del perfil sobre siete fechas civiles y la evalua completa aunque el rango la corte', function (): void {
    /*
     * Decision 6 de la ficha: toda semana que toque el rango se evalua sobre sus
     * siete dias, incluidos los de fuera. Aqui el rango es un solo dia —el jueves
     * 12— y la semana del lunes 9 al domingo 15 suma 41 h.
     *
     * Evaluar solo la parte de dentro habria dado 8 h y ninguna alerta, y sobre
     * todo un total que no suma lo que la persona ve en su registro.
     */
    $days = [];

    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $date) {
        $days[] = evaluationDay($date, firstIn: $date.' 09:00', lastOut: $date.' 17:00', totalMinutes: 8 * 60 + 12);
    }

    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        $days,
        DateRange::between('2026-03-12', '2026-03-12'),
    );

    expect(ruleNamesOf($findings))->toBe(['weekly_excess'])
        ->and($findings[0]->measuredMinutes)->toBe(2460)
        ->and($findings[0]->thresholdMinutes)->toBe(2400)
        ->and($findings[0]->differenceMinutes)->toBe(60)
        ->and($findings[0]->workDate)->toBeNull()
        ->and($findings[0]->week?->startsOn)->toBe('2026-03-09')
        ->and($findings[0]->week?->endsOn)->toBe('2026-03-15')
        // Ningun tramo por si solo explica el exceso de una semana.
        ->and($findings[0]->shiftEntryUuid)->toBeNull();
})->group('RN-17', 'RF-PA-06');

it('corta la semana por donde diga el perfil y no siempre por el lunes', function (): void {
    /*
     * Con `week_starts_on = 7` la semana va de domingo a sabado. Las mismas cinco
     * jornadas de arriba caen entonces en DOS semanas —el domingo 8 no existe
     * aqui, asi que la del domingo 8 al sabado 14 recoge el lunes 9 al viernes
     * 13— y el total es el mismo; lo que cambia es el rotulo de la semana.
     *
     * Es el caso que `date_trunc('week')` no sabe resolver: solo empieza en lunes.
     */
    $days = [];

    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $date) {
        $days[] = evaluationDay($date, firstIn: $date.' 09:00', lastOut: $date.' 17:00', totalMinutes: 8 * 60 + 12);
    }

    $findings = (new ComplianceEvaluation(evaluationProfile(weekStartsOn: 7)))->evaluate(
        $days,
        DateRange::between('2026-03-12', '2026-03-12'),
    );

    expect(ruleNamesOf($findings))->toBe(['weekly_excess'])
        ->and($findings[0]->week?->startsOn)->toBe('2026-03-08')
        ->and($findings[0]->week?->endsOn)->toBe('2026-03-14')
        ->and($findings[0]->measuredMinutes)->toBe(2460);
})->group('RN-17', 'RF-PD-07', 'RF-PA-06');

it('no alerta mientras lo cerrado de la jornada no supere el umbral', function (): void {
    /*
     * El tramo abierto vale cero en la proyeccion —el registro legal suma lo
     * fichado, no lo que va corriendo (RN-06)— asi que cuatro horas cerradas mas
     * nueve abiertas **no** son una jornada excesiva hoy. Lo seran la noche
     * siguiente al cierre, cuando el dia sea un hecho; mientras tanto, lo que ese
     * tramo tiene de raro lo dice RN-08, con su propio umbral y su incidencia.
     */
    $open = evaluationDay('2026-03-14', firstIn: '2026-03-14 09:00', totalMinutes: 240, hasOpenShift: true);

    $findings = (new ComplianceEvaluation(evaluationProfile()))
        ->evaluate([$open], DateRange::between('2026-03-14', '2026-03-14'));

    expect(ruleNamesOf($findings))->toBe([]);
})->group('RN-11', 'RF-PA-06');

it('alerta con lo ya cerrado y ademas marca la jornada abierta', function (): void {
    // La otra mitad: una jornada que YA supera el umbral con lo cerrado alerta, y
    // la marca viaja. `has_open_shift` es un aviso de lectura —«este total puede
    // crecer»— y no una segunda alerta.
    $long = evaluationDay('2026-03-14', firstIn: '2026-03-14 09:00', totalMinutes: 9 * 60 + 1, hasOpenShift: true);

    $findings = (new ComplianceEvaluation(evaluationProfile()))
        ->evaluate([$long], DateRange::between('2026-03-14', '2026-03-14'));

    expect(ruleNamesOf($findings))->toBe(['daily_excess'])
        ->and($findings[0]->hasOpenShift)->toBeTrue()
        ->and($findings[0]->differenceMinutes)->toBe(1)
        // RN-11 no se cuelga de ningun tramo: ninguno por si solo explica el dia.
        ->and($findings[0]->shiftEntryUuid)->toBeNull();
})->group('RN-11', 'RF-PA-06');

it('no emite RN-12 mientras la regla siga suspendida, y aun asi publica su umbral', function (): void {
    /*
     * `ComplianceRuleSuspension` es el unico sitio donde vive esa decision, y la
     * tarea 3.5 la vacia alli. Mientras tanto un tramo continuado de 8 h no
     * produce hallazgo — pero la regla **no se calla**: `meta.rules[]` lleva su
     * umbral, `evaluated: false` y el motivo, para que la pantalla pueda decir
     * «no se evalua hasta que exista la pausa declarada».
     *
     * Callarla seria peor que no tenerla: quien no ve alertas de pausas creeria
     * que nadie encadena ocho horas.
     */
    $evaluation = new ComplianceEvaluation(evaluationProfile());

    $day = evaluationDay(
        '2026-03-14',
        firstIn: '2026-03-14 09:00',
        lastOut: '2026-03-14 17:00',
        totalMinutes: 480,
        longestFrom: '2026-03-14 09:00',
        longestTo: '2026-03-14 17:00',
    );

    expect(ruleNamesOf($evaluation->evaluate([$day], DateRange::between('2026-03-14', '2026-03-14'))))->toBe([]);

    $suspended = array_values(array_filter(
        $evaluation->appliedRules(),
        static fn (ComplianceRuleStatus $r): bool => $r->rule === ComplianceRuleName::MissingBreak,
    ))[0];

    expect($suspended->evaluated)->toBeFalse()
        ->and($suspended->suspensionReason)->toBe(ComplianceSuspensionReason::AwaitingDeclaredBreak)
        ->and($suspended->thresholdMinutes)->toBe(6 * 60)
        ->and($suspended->requirement())->toBe('RN-12');
})->group('RN-12', 'RF-PA-06');

it('publica las cuatro reglas en el orden del documento, con su umbral del perfil', function (): void {
    // El contrato promete las cuatro siempre, se filtre o no: el criterio es parte
    // de la vista. Y en el orden RN-10, RN-11, RN-12, RN-17.
    $rules = (new ComplianceEvaluation(evaluationProfile(restHours: 10, dailyHours: 8, breakAfterHours: 5, weeklyHours: 38)))
        ->appliedRules();

    expect(array_map(static fn (ComplianceRuleStatus $r): string => $r->requirement(), $rules))
        ->toBe(['RN-10', 'RN-11', 'RN-12', 'RN-17'])
        ->and(array_map(static fn (ComplianceRuleStatus $r): int => $r->thresholdMinutes, $rules))
        ->toBe([600, 480, 300, 2280])
        ->and(array_map(static fn (ComplianceRuleStatus $r): bool => $r->evaluated, $rules))
        ->toBe([true, true, false, true]);
})->group('RF-PA-06', 'RF-PD-07');

it('ordena por persona, luego por jornada o semana y por ultimo por regla', function (): void {
    /*
     * Orden estable y previsible: quien compara dos capturas de la misma consulta
     * no puede ver las filas cambiadas de sitio. El desempate llega hasta el
     * identificador porque dos personas con el mismo nombre y apellidos existen.
     */
    $ferrer = new ComplianceEmployee('0199aaaa-0000-7000-8000-000000000001', 'E1', 'Lucia', 'Ferrer', 4, 'Recepcion');
    $amrani = evaluationEmployee();

    $days = [
        evaluationDay('2026-03-10', firstIn: '2026-03-10 09:00', lastOut: '2026-03-10 23:00', totalMinutes: 9 * 60 + 30, employee: $ferrer),
        evaluationDay('2026-03-09', firstIn: '2026-03-09 09:00', lastOut: '2026-03-09 23:00', totalMinutes: 9 * 60 + 30, employee: $ferrer),
        evaluationDay('2026-03-09', firstIn: '2026-03-09 09:00', lastOut: '2026-03-09 23:00', totalMinutes: 9 * 60 + 30, employee: $amrani),
    ];

    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        $days,
        DateRange::between('2026-03-09', '2026-03-10'),
    );

    $order = array_map(
        static fn (ComplianceFinding $f): string => $f->employee->lastName.' '.($f->workDate ?? $f->week?->startsOn).' '.$f->rule->value,
        $findings,
    );

    expect($order)->toBe([
        'Amrani 2026-03-09 daily_excess',
        'Ferrer 2026-03-09 daily_excess',
        'Ferrer 2026-03-10 daily_excess',
    ]);
})->group('RF-PA-06');

it('ordena las reglas de una misma jornada en el orden del documento', function (): void {
    // Una jornada que incumple dos reglas a la vez: entra nueve horas despues de
    // salir (RN-10) y hace 9 h 30 (RN-11). Las dos salen, y en ese orden.
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [
            evaluationDay('2026-03-09', firstIn: '2026-03-09 09:00', lastOut: '2026-03-09 17:00', totalMinutes: 480),
            evaluationDay('2026-03-10', firstIn: '2026-03-10 02:00', lastOut: '2026-03-10 11:30', previousLastOut: '2026-03-09 17:00', totalMinutes: 570),
        ],
        DateRange::between('2026-03-09', '2026-03-10'),
    );

    expect(ruleNamesOf($findings))->toBe(['insufficient_rest', 'daily_excess'])
        ->and($findings[0]->workDate)->toBe('2026-03-10')
        ->and($findings[1]->workDate)->toBe('2026-03-10');
})->group('RN-10', 'RN-11', 'RF-PA-06');

it('pone la semana despues de las jornadas de su primer dia', function (): void {
    // La semanal se ordena por el primer dia de su semana, que es lo que la situa
    // junto a las jornadas de esos mismos dias en la pantalla. El lunes 9 lleva
    // una jornada excesiva, y la semana entera se pasa de las cuarenta horas.
    $days = [];

    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $date) {
        $days[] = evaluationDay($date, firstIn: $date.' 09:00', lastOut: $date.' 18:00', totalMinutes: 9 * 60 + 1);
    }

    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        $days,
        DateRange::between('2026-03-09', '2026-03-13'),
    );

    $orden = array_map(
        static fn (ComplianceFinding $f): string => ($f->workDate ?? $f->week?->startsOn).' '.$f->rule->value,
        $findings,
    );

    expect($orden)->toBe([
        '2026-03-09 daily_excess',
        '2026-03-09 weekly_excess',
        '2026-03-10 daily_excess',
        '2026-03-11 daily_excess',
        '2026-03-12 daily_excess',
        '2026-03-13 daily_excess',
    ]);
})->group('RN-17', 'RF-PA-06');

it('evalua todas las semanas que toca el rango, no solo la primera', function (): void {
    // Un rango de dos semanas con el exceso en la SEGUNDA. Si el recorrido se
    // parara en la semana de `from`, no saldria nada y nadie lo notaria.
    $days = [];

    // Semana del 9 al 15: cinco jornadas de 6 h, treinta horas. No incumple.
    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $date) {
        $days[] = evaluationDay($date, firstIn: $date.' 09:00', lastOut: $date.' 15:00', totalMinutes: 360);
    }

    // Semana del 16 al 22: cinco jornadas de 8 h 12, cuarenta y una horas.
    foreach (['2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20'] as $date) {
        $days[] = evaluationDay($date, firstIn: $date.' 09:00', lastOut: $date.' 17:12', totalMinutes: 8 * 60 + 12);
    }

    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        $days,
        DateRange::between('2026-03-09', '2026-03-20'),
    );

    expect(ruleNamesOf($findings))->toBe(['weekly_excess'])
        ->and($findings[0]->week?->startsOn)->toBe('2026-03-16');
})->group('RN-17', 'RF-PA-06');

it('no señala una semana en la que esa persona no ficho ningun dia', function (): void {
    // Cero minutos no incumplen nada, y una semana sin jornadas no puede producir
    // un hallazgo colgado de nadie.
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [evaluationDay('2026-03-09', firstIn: '2026-03-09 09:00', lastOut: '2026-03-09 17:00', totalMinutes: 480)],
        // El rango toca dos semanas y la segunda esta vacia.
        DateRange::between('2026-03-09', '2026-03-20'),
    );

    expect(ruleNamesOf($findings))->toBe([]);
})->group('RN-17', 'RF-PA-06');

it('marca la semana cuando alguna de sus jornadas sigue abierta', function (): void {
    // El total de la semana puede crecer, y la pantalla tiene que decirlo: es un
    // aviso de lectura, no una segunda alerta.
    $days = [
        evaluationDay('2026-03-09', firstIn: '2026-03-09 09:00', lastOut: '2026-03-09 21:00', totalMinutes: 12 * 60),
        evaluationDay('2026-03-10', firstIn: '2026-03-10 09:00', lastOut: '2026-03-10 21:00', previousLastOut: '2026-03-09 21:00', totalMinutes: 12 * 60),
        evaluationDay('2026-03-11', firstIn: '2026-03-11 09:00', lastOut: '2026-03-11 21:00', previousLastOut: '2026-03-10 21:00', totalMinutes: 12 * 60),
        evaluationDay('2026-03-12', firstIn: '2026-03-12 09:00', previousLastOut: '2026-03-11 21:00', totalMinutes: 300, hasOpenShift: true),
    ];

    $findings = array_values(array_filter(
        (new ComplianceEvaluation(evaluationProfile()))->evaluate($days, DateRange::between('2026-03-09', '2026-03-12')),
        static fn (ComplianceFinding $f): bool => $f->rule === ComplianceRuleName::WeeklyExcess,
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->hasOpenShift)->toBeTrue()
        ->and($findings[0]->measuredMinutes)->toBe(41 * 60);
})->group('RN-17', 'RF-PA-06');

it('no marca la semana cuyas jornadas estan todas cerradas', function (): void {
    // El contraste del caso de arriba, y el que impide que la marca se quede
    // encendida siempre: una semana cerrada dice `has_open_shift: false`, y eso es
    // lo que le permite a la pantalla afirmar que ese total ya no va a crecer.
    $days = [];

    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $date) {
        $days[] = evaluationDay($date, firstIn: $date.' 09:00', lastOut: $date.' 17:12', totalMinutes: 8 * 60 + 12);
    }

    $findings = (new ComplianceEvaluation(evaluationProfile()))
        ->evaluate($days, DateRange::between('2026-03-09', '2026-03-13'));

    expect(ruleNamesOf($findings))->toBe(['weekly_excess'])
        ->and($findings[0]->hasOpenShift)->toBeFalse()
        ->and($findings[0]->measuredMinutes)->toBe(2460);
})->group('RN-17', 'RF-PA-06');

it('no señala las jornadas de fuera del rango que solo estan ahi para dar contexto', function (): void {
    // La jornada anterior a `from` se carga para que RN-10 se pueda evaluar en la
    // primera del rango. Señalarla seria enseñar alertas de un periodo que nadie
    // pidio.
    $findings = (new ComplianceEvaluation(evaluationProfile()))->evaluate(
        [
            evaluationDay('2026-03-13', firstIn: '2026-03-13 09:00', lastOut: '2026-03-13 23:00', totalMinutes: 14 * 60),
            evaluationDay('2026-03-14', firstIn: '2026-03-14 09:00', lastOut: '2026-03-14 17:00', previousLastOut: '2026-03-13 23:00', totalMinutes: 480),
        ],
        DateRange::between('2026-03-14', '2026-03-14'),
    );

    // La del 13 excede las 9 h y no sale; la del 14 si alerta de descanso (10 h).
    expect(ruleNamesOf($findings))->toBe(['insufficient_rest'])
        ->and($findings[0]->workDate)->toBe('2026-03-14');
})->group('RN-10', 'RF-PA-06');
