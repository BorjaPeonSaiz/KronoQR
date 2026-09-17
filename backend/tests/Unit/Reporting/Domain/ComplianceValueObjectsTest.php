<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ComplianceEmployee;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFinding;
use App\Modules\Reporting\Domain\ValueObject\ComplianceIncidentLink;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleName;
use App\Modules\Reporting\Domain\ValueObject\ComplianceShiftSegment;
use App\Modules\Reporting\Domain\ValueObject\ComplianceTotals;
use App\Modules\Reporting\Domain\ValueObject\ComplianceWeek;
use App\Modules\Shared\Domain\ValueObject\ComplianceRule;
use Tests\Support\Time\Instants;

/*
 * Los objetos de valor sobre los que se apoya la vista de cumplimiento
 * (RF-PA-06, tarea 3.4).
 *
 * Cada uno guarda una invariante pequeña que, si se pierde, no rompe nada
 * visiblemente: una semana de seis dias, un hallazgo que dice «faltan 0
 * minutos», un recuento sin la clave de una regla. Todas producen una pantalla
 * que se lee bien y afirma algo falso, que es la peor forma de fallar de una
 * vista de cumplimiento.
 */

function empleadoDeCumplimiento(string $uuid = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'): ComplianceEmployee
{
    return new ComplianceEmployee($uuid, 'E7QK2MXPR', 'Youssef', 'Amrani', 3, 'Cocina');
}

it('la semana del perfil tiene siempre siete fechas civiles consecutivas', function (int $weekStartsOn, string $startsOn, string $endsOn): void {
    // Seis o siete dias no es un detalle: el umbral con el que se compara es
    // semanal, y una semana corta produciria totales que nunca lo alcanzan.
    $week = ComplianceWeek::containing('2026-03-12', $weekStartsOn);

    expect($week->startsOn)->toBe($startsOn)
        ->and($week->endsOn)->toBe($endsOn)
        ->and($week->days())->toHaveCount(7)
        ->and($week->days()[0])->toBe($startsOn)
        ->and($week->days()[6])->toBe($endsOn);
})->with([
    // El jueves 12 de marzo de 2026, con cada uno de los siete inicios posibles.
    'lunes' => [1, '2026-03-09', '2026-03-15'],
    'martes' => [2, '2026-03-10', '2026-03-16'],
    'miercoles' => [3, '2026-03-11', '2026-03-17'],
    'jueves' => [4, '2026-03-12', '2026-03-18'],
    'viernes' => [5, '2026-03-06', '2026-03-12'],
    'sabado' => [6, '2026-03-07', '2026-03-13'],
    'domingo' => [7, '2026-03-08', '2026-03-14'],
])->group('RN-17', 'RF-PD-07');

it('encadena semanas hacia delante y hacia atras sin saltarse ningun dia', function (): void {
    $week = ComplianceWeek::containing('2026-03-12', 1);

    expect($week->next()->startsOn)->toBe('2026-03-16')
        ->and($week->next()->endsOn)->toBe('2026-03-22')
        ->and($week->previous()->startsOn)->toBe('2026-03-02')
        ->and($week->previous()->endsOn)->toBe('2026-03-08')
        // Y el encadenamiento es reversible: ir y volver deja la misma semana.
        ->and($week->next()->previous()->startsOn)->toBe($week->startsOn);
})->group('RN-17');

it('sabe si toca un rango, incluidos los bordes', function (): void {
    // Es lo que decide si una semana se evalua: tocar el rango por un solo dia
    // basta, porque la semana se evalua completa (decision 6 de la ficha).
    $week = ComplianceWeek::containing('2026-03-12', 1);

    expect($week->touches('2026-03-15', '2026-03-31'))->toBeTrue()
        ->and($week->touches('2026-02-01', '2026-03-09'))->toBeTrue()
        ->and($week->touches('2026-03-16', '2026-03-31'))->toBeFalse()
        ->and($week->touches('2026-02-01', '2026-03-08'))->toBeFalse();
})->group('RN-17');

it('el cambio de hora no acorta ni alarga la semana', function (): void {
    // RN-09: la semana del cambio de marzo de 2026 —domingo 29— sigue teniendo
    // siete fechas civiles. No hay ningun instante de por medio, y esa ausencia
    // es la garantia.
    $week = ComplianceWeek::containing('2026-03-29', 1);

    expect($week->startsOn)->toBe('2026-03-23')
        ->and($week->endsOn)->toBe('2026-03-29')
        ->and($week->days())->toHaveCount(7);
})->group('RN-09', 'RN-17');

it('la semana del cambio de octubre tambien tiene siete fechas', function (): void {
    // El gemelo del de arriba y el que faltaba: el 25 de octubre de 2026 el reloj
    // RETRASA y esa madrugada dura 25 horas. La semana sigue teniendo siete fechas
    // civiles —el domingo 25 es el septimo dia de la semana que empezo el lunes
    // 19— y el dia de 25 horas no la desplaza.
    //
    // Un `modify('+7 days')` sobre una fecha con zona variable seria lo que aqui
    // fallaria: los dos cambios de hora del año se prueban porque desplazan en
    // direcciones contrarias y un solo caso deja pasar la mitad de los errores.
    $week = ComplianceWeek::containing('2026-10-25', 1);

    expect($week->startsOn)->toBe('2026-10-19')
        ->and($week->endsOn)->toBe('2026-10-25')
        ->and($week->days())->toHaveCount(7)
        ->and($week->days())->toBe([
            '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22',
            '2026-10-23', '2026-10-24', '2026-10-25',
        ])
        // Y encadenar por encima del cambio tampoco pierde ni gana un dia.
        ->and($week->next()->startsOn)->toBe('2026-10-26')
        ->and($week->previous()->endsOn)->toBe('2026-10-18');
})->group('RN-09', 'RN-17');

it('el descanso se mide en minutos enteros y cero minutos sigue siendo descanso', function (): void {
    // Empezar exactamente cuando termino la jornada anterior es el peor
    // cumplimiento de RN-10 que se puede registrar sin solapar: cero minutos, no
    // «no se evalua».
    $sinHueco = new ComplianceFacts(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        firstInAt: Instants::inMadrid('2026-03-10 06:00'),
        lastOutAt: Instants::inMadrid('2026-03-10 14:00'),
        previousLastOutAt: Instants::inMadrid('2026-03-10 06:00'),
        totalMinutes: 480,
        hasOpenShift: false,
        longestClosedSegment: null,
        openingShiftEntryUuid: null,
    );

    expect($sinHueco->restMinutes())->toBe(0);

    // Un segundo de solape ya no es descanso: es RN-02, y no se mide.
    $solapada = new ComplianceFacts(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        firstInAt: Instants::inMadrid('2026-03-10 06:00'),
        lastOutAt: Instants::inMadrid('2026-03-10 14:00'),
        previousLastOutAt: Instants::inMadrid('2026-03-10 06:01'),
        totalMinutes: 480,
        hasOpenShift: false,
        longestClosedSegment: null,
        openingShiftEntryUuid: null,
    );

    expect($solapada->restMinutes())->toBeNull();

    // Y sin jornada anterior tampoco hay nada que medir.
    $primera = new ComplianceFacts(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        firstInAt: Instants::inMadrid('2026-03-10 06:00'),
        lastOutAt: Instants::inMadrid('2026-03-10 14:00'),
        previousLastOutAt: null,
        totalMinutes: 480,
        hasOpenShift: false,
        longestClosedSegment: null,
        openingShiftEntryUuid: null,
    );

    expect($primera->restMinutes())->toBeNull();
})->group('RN-10', 'RF-PA-06');

it('no mide el descanso de una jornada cuyos tramos se anularon todos', function (): void {
    // RN-13: anular no borra, deja la jornada sin ningun tramo vigente. La
    // proyeccion conserva la fila con total cero y sin `first_in_at`, asi que no
    // hay entrada contra la que medir el descanso —aunque si conste la salida de
    // la jornada anterior—. Medirlo contra cualquier otra cosa seria inventarse
    // una hora de entrada para alguien que ese dia no tiene ninguna.
    $anulada = new ComplianceFacts(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        firstInAt: null,
        lastOutAt: null,
        previousLastOutAt: Instants::inMadrid('2026-03-09 23:30'),
        totalMinutes: 0,
        hasOpenShift: false,
        longestClosedSegment: null,
        openingShiftEntryUuid: null,
    );

    expect($anulada->restMinutes())->toBeNull();
})->group('RN-10', 'RN-13', 'RF-PA-06');

it('un solo segundo de solape ya no es descanso', function (): void {
    // El limite exacto entre RN-10 y RN-02. Cero minutos es descanso; un segundo
    // por debajo de cero es un solape, del que responde el esquema, y medirlo aqui
    // daria un descanso negativo que se leeria como el peor incumplimiento
    // posible.
    $unSegundo = new ComplianceFacts(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        firstInAt: Instants::inMadrid('2026-03-10 05:59:59'),
        lastOutAt: Instants::inMadrid('2026-03-10 14:00'),
        previousLastOutAt: Instants::inMadrid('2026-03-10 06:00:00'),
        totalMinutes: 480,
        hasOpenShift: false,
        longestClosedSegment: null,
        openingShiftEntryUuid: null,
    );

    expect($unSegundo->restMinutes())->toBeNull();
})->group('RN-10', 'RN-02', 'RF-PA-06');

it('el hallazgo de pausa señala el tramo continuado y no la semana', function (): void {
    // RN-12 es la unica regla diaria que se cuelga de un tramo concreto ademas de
    // RN-10: el continuado que se paso del maximo, que es el que quien revisa
    // necesita mirar.
    $segment = new ComplianceShiftSegment(
        '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
        Instants::inMadrid('2026-03-10 09:00'),
        Instants::inMadrid('2026-03-10 17:30'),
    );

    $finding = ComplianceFinding::missingBreak(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        segment: $segment,
        thresholdMinutes: 360,
        hasOpenShift: false,
    );

    expect($finding->rule)->toBe(ComplianceRuleName::MissingBreak)
        ->and($finding->measuredMinutes)->toBe(510)
        ->and($finding->thresholdMinutes)->toBe(360)
        ->and($finding->differenceMinutes)->toBe(150)
        ->and($finding->shiftEntryUuid)->toBe('0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11')
        ->and($finding->workDate)->toBe('2026-03-10')
        ->and($finding->week)->toBeNull();
})->group('RN-12', 'RF-PA-06');

it('el tramo continuo trunca los segundos hacia abajo', function (): void {
    // 6 h 0 min 59 s son 360 minutos y no 361, asi que no alerta con el umbral de
    // seis horas: redondear al alza convertiria el limite abierto del doc 01 en
    // cerrado por accidente.
    $segment = new ComplianceShiftSegment(
        '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
        Instants::inMadrid('2026-03-10 09:00:00'),
        Instants::inMadrid('2026-03-10 15:00:59'),
    );

    expect($segment->minutes())->toBe(360);
})->group('RN-12', 'RF-PA-06');

it('rechaza un hallazgo que no describe ningun incumplimiento', function (): void {
    // «Faltan 0 minutos» es un aviso que nadie puede defender ante un empleado.
    expect(fn (): ComplianceFinding => ComplianceFinding::insufficientRest(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        restMinutes: 720,
        thresholdMinutes: 720,
        openingShiftEntryUuid: null,
        hasOpenShift: false,
    ))->toThrow(InvalidArgumentException::class);
})->group('RF-PA-06');

it('conserva el hallazgo entero al enlazarle la incidencia', function (): void {
    // El enlace ocurre despues de evaluar, en el caso de uso: si de paso cambiara
    // cualquier otro campo, la pantalla enseñaria un numero distinto del que se
    // midio.
    $original = ComplianceFinding::dailyExcess(
        employee: empleadoDeCumplimiento(),
        workDate: '2026-03-10',
        workedMinutes: 570,
        thresholdMinutes: 540,
        hasOpenShift: true,
    );

    $enlazado = $original->linkedTo(new ComplianceIncidentLink(42, 'open'));

    expect($enlazado->incident?->id)->toBe(42)
        ->and($enlazado->incident?->status)->toBe('open')
        ->and($enlazado->rule)->toBe($original->rule)
        ->and($enlazado->workDate)->toBe($original->workDate)
        ->and($enlazado->measuredMinutes)->toBe($original->measuredMinutes)
        ->and($enlazado->thresholdMinutes)->toBe($original->thresholdMinutes)
        ->and($enlazado->differenceMinutes)->toBe($original->differenceMinutes)
        ->and($enlazado->hasOpenShift)->toBe($original->hasOpenShift)
        // Y el original no se toca: es un objeto de valor.
        ->and($original->incident)->toBeNull();
})->group('RF-PA-06');

it('cuenta las cuatro reglas siempre, tambien las que no encontraron nada', function (): void {
    // Una clave que desapareciera obligaria al panel a inventarse el cero.
    $totals = ComplianceTotals::of([
        ComplianceFinding::dailyExcess(empleadoDeCumplimiento(), '2026-03-10', 570, 540, false),
        ComplianceFinding::dailyExcess(empleadoDeCumplimiento(), '2026-03-11', 600, 540, false),
        ComplianceFinding::dailyExcess(
            new ComplianceEmployee('0199aaaa-0000-7000-8000-000000000001', 'E1', 'Lucia', 'Ferrer', 4, 'Recepcion'),
            '2026-03-10',
            570,
            540,
            false,
        ),
    ], employeesEvaluated: 48);

    expect($totals->byRule)->toBe([
        'insufficient_rest' => 0,
        'daily_excess' => 3,
        'missing_break' => 0,
        'weekly_excess' => 0,
    ])
        // Personas, no hallazgos: una misma persona con dos no cuenta dos veces.
        ->and($totals->employeesAffected)->toBe(2)
        ->and($totals->employeesEvaluated)->toBe(48);

    // Y sin hallazgos, las cuatro claves siguen ahi.
    expect(ComplianceTotals::of([], 48)->byRule)->toBe([
        'insufficient_rest' => 0,
        'daily_excess' => 0,
        'missing_break' => 0,
        'weekly_excess' => 0,
    ]);
})->group('RF-PA-06');

it('traduce entre el vocabulario del documento y el de la bandeja sin perder a nadie', function (): void {
    // Los nombres coinciden con `incidents.type` en tres de las cuatro; en RN-11
    // no puede ser, porque alli `long_shift` cubre tambien el tramo suelto de
    // RN-08. La traduccion vive en un solo sitio.
    expect(ComplianceRuleName::fromRule(ComplianceRule::MinimumRestBetweenWorkDays))
        ->toBe(ComplianceRuleName::InsufficientRest)
        ->and(ComplianceRuleName::fromRule(ComplianceRule::MaximumDailyWorkingTime))
        ->toBe(ComplianceRuleName::DailyExcess)
        ->and(ComplianceRuleName::fromRule(ComplianceRule::BreakInContinuousShift))
        ->toBe(ComplianceRuleName::MissingBreak)
        ->and(ComplianceRuleName::fromRule(ComplianceRule::MaximumWeeklyWorkingTime))
        ->toBe(ComplianceRuleName::WeeklyExcess);

    expect(ComplianceRuleName::InsufficientRest->incidentType())->toBe('insufficient_rest')
        ->and(ComplianceRuleName::DailyExcess->incidentType())->toBe('long_shift')
        ->and(ComplianceRuleName::MissingBreak->incidentType())->toBe('missing_break')
        // RN-17 no abre incidencia y nunca lo hara.
        ->and(ComplianceRuleName::WeeklyExcess->incidentType())->toBeNull()
        ->and(ComplianceRuleName::WeeklyExcess->isWeekly())->toBeTrue()
        ->and(ComplianceRuleName::DailyExcess->isWeekly())->toBeFalse();

    // Y las cuatro estan en el orden del documento, que es el que promete el
    // contrato: `cases()` no lo garantiza, este metodo si.
    expect(array_map(
        static fn (ComplianceRuleName $r): string => $r->requirement(),
        ComplianceRuleName::inRequirementOrder(),
    ))->toBe(['RN-10', 'RN-11', 'RN-12', 'RN-17'])
        ->and(ComplianceRuleName::inRequirementOrder())->toHaveCount(\count(ComplianceRuleName::cases()));
})->group('RF-PA-06', 'RN-17');

it('ordena a las personas por apellidos, nombre e identificador', function (): void {
    // Dos personas con el mismo nombre y apellidos existen: sin el desempate por
    // identificador, dos ejecuciones de la misma consulta podrian devolverlas en
    // distinto orden y quien compara dos capturas creeria que algo ha cambiado.
    $primera = new ComplianceEmployee('0199aaaa-0000-7000-8000-000000000001', 'E1', 'Ana', 'Lopez', null, null);
    $segunda = new ComplianceEmployee('0199aaaa-0000-7000-8000-000000000002', 'E2', 'Ana', 'Lopez', null, null);

    expect($primera->sortKey())->toBeLessThan($segunda->sortKey())
        ->and($primera->fullName())->toBe('Ana Lopez')
        // Y el orden no depende de mayusculas.
        ->and((new ComplianceEmployee('u', 'E3', 'Ana', 'alvarez', null, null))->sortKey())
        ->toBeLessThan($primera->sortKey());
})->group('RF-PA-06');
