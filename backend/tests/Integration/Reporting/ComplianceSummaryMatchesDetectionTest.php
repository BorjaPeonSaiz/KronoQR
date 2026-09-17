<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Query\ComplianceSummaryCriteria;
use App\Modules\Reporting\Application\Query\ReadComplianceSummary;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFinding;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La vista de cumplimiento y la bandeja de incidencias cuentan lo mismo**
 * (RF-PA-06, RN-10, RN-11; paso 4 y decision 5 de la ficha 3.4).
 *
 * ## Que se afirma, y por que hace falta afirmarlo
 *
 * `Attendance\Domain\Policy\AnomalyDetectionPolicy` evalua RN-10 y RN-11 sobre el
 * **agregado** `WorkDay`; `Reporting\Domain\Policy\ComplianceEvaluation` las
 * evalua sobre un **modelo de lectura** plano. Son dos recorridos distintos sobre
 * los mismos datos, en dos modulos que no pueden verse (doc 02 §1.6), y lo unico
 * que comparten son los predicados de `CompliancePolicy`.
 *
 * Con eso basta para que coincidan **hoy**. Lo que esta prueba impide es que
 * dejen de coincidir mañana sin que nadie se entere: un responsable que abre la
 * pantalla, ve tres alertas de descanso y encuentra dos en su bandeja no tiene
 * forma de saber cual de las dos miente, y la que va a defender ante un empleado
 * es la que tenga delante.
 *
 * Por eso se siembran jornadas reales, se ejecuta `attendance:detect-incidents` y
 * se comparan **los conjuntos `(empleado, jornada)`**, no los recuentos: dos
 * conjuntos del mismo tamaño pueden describir dias distintos.
 *
 * ## Por que es de integracion y no de feature
 *
 * Porque lo que se cruza son dos caminos que solo existen juntos contra
 * PostgreSQL: el `lag()` del lector de hechos y el `one_incident_per_finding` de
 * `incidents`. Un doble en memoria daria los dos por buenos.
 *
 * ## RN-12 no entra, y no es un olvido
 *
 * Esta suspendida mientras el fichaje de pausa siga desactivado en la instalacion
 * (`ComplianceRuleSuspension`, ADR-024, RF-AT-12), que es como nace: ni la
 * bandeja la abre ni la vista la emite. En cuanto el hotel active
 * `ATTENDANCE_BREAK_CLOCKING`, las dos empiezan a contarla a la vez — que es
 * exactamente la propiedad que esta prueba describe.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // La revision diaria solo mira hacia atras `COMPLIANCE_INCIDENT_LOOKBACK_DAYS`
    // (RF-PR-01): no reprocesa el historico. Se amplia para que la pasada alcance
    // las jornadas sembradas, que es lo que permite comparar los dos conjuntos.
    Config::set('compliance.incident_detection.lookback_days', 30);

    FrozenTime::at('2026-03-31 04:30:00');
});

/**
 * @return array{site: int, department: int, employees: array<string, string>}
 */
function escenarioComparadoDeCumplimiento(): array
{
    $site = WorkforceFixtures::site('Hotel comparado', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');

    $incumple = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');
    $cumple = WorkforceFixtures::employee($site, $department, 'active', 'Lucia', 'Ferrer');

    // Quien incumple: 09:00 → 17:00 el lunes, y el martes entra a las 02:00
    // —nueve horas de descanso, por debajo de las doce— con una jornada de 9 h 30,
    // por encima de las nueve ordinarias. Dos hallazgos, dos reglas.
    PeriodReportFixtures::workDay($site, $incumple, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::workDay($site, $incumple, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');

    // Y una jornada mas, otra semana, para que el conjunto no sea trivialmente de
    // un solo dia.
    PeriodReportFixtures::workDay($site, $incumple, '2026-03-16', '2026-03-16 22:00', '2026-03-17 06:00');
    PeriodReportFixtures::workDay($site, $incumple, '2026-03-17', '2026-03-17 12:00', '2026-03-17 20:00');

    // Quien cumple: ocho horas al dia con mas de doce de descanso entre jornadas.
    PeriodReportFixtures::workDay($site, $cumple, '2026-03-09', '2026-03-09 08:00', '2026-03-09 16:00');
    PeriodReportFixtures::workDay($site, $cumple, '2026-03-10', '2026-03-10 08:00', '2026-03-10 16:00');

    /*
     * Y DOS personas pegadas al limite, una a cada lado, que son las que de verdad
     * atan la comparacion.
     *
     * Sin ellas, un `<=` en lugar de un `<` en cualquiera de los dos lados dejaria
     * los conjuntos idénticos y la prueba pasaría: los casos de arriba están a
     * tres horas del umbral. Con ellas, mover el operador en la bandeja o en la
     * vista rompe inmediatamente la igualdad.
     */
    $justo = WorkforceFixtures::employee($site, $department, 'active', 'Marta', 'Borde');
    $unMinutoMenos = WorkforceFixtures::employee($site, $department, 'active', 'Pablo', 'Casi');

    // Doce horas EXACTAS de descanso: ninguno de los dos lo señala.
    PeriodReportFixtures::workDay($site, $justo, '2026-03-09', '2026-03-09 06:00', '2026-03-09 14:00');
    PeriodReportFixtures::workDay($site, $justo, '2026-03-10', '2026-03-10 02:00', '2026-03-10 09:00');

    // Once horas y cincuenta y nueve minutos: los dos lo señalan.
    PeriodReportFixtures::workDay($site, $unMinutoMenos, '2026-03-09', '2026-03-09 06:00', '2026-03-09 14:00');
    PeriodReportFixtures::workDay($site, $unMinutoMenos, '2026-03-10', '2026-03-10 01:59', '2026-03-10 09:00');

    return [
        'site' => $site,
        'department' => $department,
        'employees' => [
            'incumple' => $incumple,
            'cumple' => $cumple,
            'justo' => $justo,
            'unMinutoMenos' => $unMinutoMenos,
        ],
    ];
}

/**
 * Los pares `(empleado, jornada)` que la VISTA señala para una regla.
 *
 * @return list<string>
 */
function paresDeLaVista(string $rule): array
{
    $summary = app(ReadComplianceSummary::class)->handle(
        new ComplianceSummaryCriteria(
            scope: AccessScope::unrestricted(),
            from: '2026-03-01',
            to: '2026-03-31',
        ),
        maxRangeDays: 92,
    );

    $pares = [];

    foreach ($summary->findings as $finding) {
        if ($finding->rule->value === $rule && $finding->workDate !== null) {
            $pares[] = $finding->employee->uuid.'|'.$finding->workDate;
        }
    }

    sort($pares);

    return $pares;
}

/**
 * Los pares `(empleado, jornada)` que la BANDEJA tiene para un tipo.
 *
 * @return list<string>
 */
function paresDeLaBandeja(string $type): array
{
    /*
     * **Sin filtrar por `shift_entry_id`, y esto es una correccion de la revision.**
     *
     * La version anterior descartaba las incidencias colgadas de un tramo para
     * comparar solo las del dia, y con eso el caso mas interesante no se
     * ejercitaba: cuando un tramo suelto ya explica el dia por RN-08, la bandeja
     * abre **una sola** incidencia `long_shift` —colgada del tramo— y no una
     * segunda por RN-11, mientras que la vista si emite su `daily_excess`. Con el
     * filtro puesto, ese dia salia en la vista y no en la bandeja, y la prueba lo
     * habria denunciado como divergencia cuando es la conducta correcta.
     *
     * Lo que se compara es la pregunta que de verdad importa —«¿hay en la bandeja
     * algo de este tipo para esta persona y esta jornada?»— y por eso se agrupa
     * con `DISTINCT`: una jornada con dos tramos desmedidos produce dos
     * incidencias y sigue siendo una sola jornada señalada.
     */
    $pares = DB::table('incidents')
        ->join('employees', 'employees.id', '=', 'incidents.employee_id')
        ->where('incidents.type', $type)
        ->distinct()
        ->selectRaw("employees.uuid || '|' || incidents.work_date AS par")
        ->pluck('par')
        ->all();

    /** @var list<string> $pares */
    $pares = array_values(array_map(
        static fn (mixed $v): string => \is_string($v) ? $v : '',
        $pares,
    ));

    sort($pares);

    return $pares;
}

it('RN-10 señala exactamente las mismas jornadas en la vista y en la bandeja', function (): void {
    escenarioComparadoDeCumplimiento();

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $vista = paresDeLaVista('insufficient_rest');

    expect($vista)->not->toBe([])
        ->and($vista)->toBe(paresDeLaBandeja('insufficient_rest'));
})->group('RN-10', 'RF-PA-06');

it('RN-11 señala exactamente las mismas jornadas en la vista y en la bandeja', function (): void {
    escenarioComparadoDeCumplimiento();

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $vista = paresDeLaVista('daily_excess');

    expect($vista)->not->toBe([])
        ->and($vista)->toBe(paresDeLaBandeja('long_shift'));
})->group('RN-11', 'RF-PA-06');

it('enlaza cada hallazgo con la incidencia abierta de la bandeja', function (): void {
    // La demostracion visible de que las dos cuentan lo mismo, y el enlace para ir
    // a resolverla: es lo que convierte la pantalla en trabajo y no en una lista.
    $escenario = escenarioComparadoDeCumplimiento();

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $summary = app(ReadComplianceSummary::class)->handle(
        new ComplianceSummaryCriteria(
            scope: AccessScope::unrestricted(),
            from: '2026-03-01',
            to: '2026-03-31',
        ),
        maxRangeDays: 92,
    );

    $descanso = array_values(array_filter(
        $summary->findings,
        static fn (ComplianceFinding $f): bool => $f->rule->value === 'insufficient_rest',
    ));

    expect($descanso)->not->toBe([]);

    $incidencia = DB::table('incidents')
        ->join('employees', 'employees.id', '=', 'incidents.employee_id')
        ->where('incidents.type', 'insufficient_rest')
        ->where('employees.uuid', $escenario['employees']['incumple'])
        ->where('incidents.work_date', $descanso[0]->workDate)
        // `select` explicito: con el `join`, un `first()` a secas trae tambien las
        // columnas de `employees` y el `id` que llega es el de la persona.
        ->select('incidents.id', 'incidents.status')
        ->first();

    expect($incidencia)->not->toBeNull()
        ->and($descanso[0]->incident)->not->toBeNull()
        ->and($descanso[0]->incident?->id)->toBe((int) ($incidencia->id ?? 0))
        ->and($descanso[0]->incident?->status)->toBe('open');
})->group('RN-10', 'RF-PA-06');

it('no enlaza nada cuando la revision diaria todavia no ha pasado', function (): void {
    // Un hallazgo sin incidencia no es un error: significa que la pasada nocturna
    // aun no ha llegado a esa jornada. La pantalla lo enseña igual —el
    // incumplimiento existe— y simplemente no ofrece el enlace.
    escenarioComparadoDeCumplimiento();

    $summary = app(ReadComplianceSummary::class)->handle(
        new ComplianceSummaryCriteria(
            scope: AccessScope::unrestricted(),
            from: '2026-03-01',
            to: '2026-03-31',
        ),
        maxRangeDays: 92,
    );

    expect($summary->findings)->not->toBe([]);

    foreach ($summary->findings as $finding) {
        expect($finding->incident)->toBeNull();
    }
})->group('RF-PA-06');

it('la regla suspendida no aparece en ninguno de los dos lados', function (): void {
    // Un tramo continuado de ocho horas supera el maximo de seis, y aun asi ni la
    // bandeja abre incidencia ni la vista emite hallazgo: la decision vive en una
    // sola decision (`ComplianceRuleSuspension`), construida desde el ajuste de
    // la instalacion, y los dos lados la reciben ya resuelta (regla dura 14).
    escenarioComparadoDeCumplimiento();

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    expect(paresDeLaVista('missing_break'))->toBe([])
        ->and(paresDeLaBandeja('missing_break'))->toBe([]);
})->group('RN-12', 'RF-PA-06');

it('cuando un tramo ya explica el dia por RN-08, la bandeja abre una sola incidencia y el hallazgo la enlaza', function (): void {
    /*
     * **La excepcion real a «cuentan lo mismo», y la unica.**
     *
     * Un turno unico de trece horas supera a la vez el maximo de tramo de RN-08
     * —operativo, `ATTENDANCE_MAX_SHIFT_HOURS`— y la jornada ordinaria de RN-11.
     * La revision diaria abre **solo** la de RN-08, colgada del tramo: es mas
     * precisa y ademas señala cual, y dos incidencias que dicen lo mismo con
     * distinta precision solo hacen ruido en la bandeja de quien las revisa.
     *
     * La vista, en cambio, emite su `daily_excess`: lo que ella mide es el dia, y
     * callarlo dejaria una jornada de trece horas fuera del recuento de jornadas
     * excesivas. No es una divergencia: es la misma jornada señalada por las dos
     * pantallas con distinta granularidad, y el hallazgo **enlaza esa misma
     * incidencia** para que quien la abra llegue a donde hay que ir.
     *
     * Esta es la conducta que el contrato describe y la que el filtro por
     * `shift_entry_id` de la version anterior de esta prueba escondia.
     */
    $site = WorkforceFixtures::site('Hotel del turno largo', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Recepcion');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Ines', 'Vega');

    // Trece horas seguidas, por encima de las doce de `ATTENDANCE_MAX_SHIFT_HOURS`.
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 06:00', '2026-03-10 19:00');

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    /** @var list<object{id: int, shift_entry_id: int|null}> $incidencias */
    $incidencias = DB::table('incidents')
        ->join('employees', 'employees.id', '=', 'incidents.employee_id')
        ->where('employees.uuid', $employee)
        ->where('incidents.type', 'long_shift')
        ->select('incidents.id', 'incidents.shift_entry_id')
        ->get()
        ->all();

    // UNA sola, y colgada del tramo: RN-11 no abre la suya porque RN-08 ya lo
    // explico.
    expect($incidencias)->toHaveCount(1)
        ->and($incidencias[0]->shift_entry_id)->not->toBeNull();

    $summary = app(ReadComplianceSummary::class)->handle(
        new ComplianceSummaryCriteria(
            scope: AccessScope::unrestricted(),
            from: '2026-03-01',
            to: '2026-03-31',
        ),
        maxRangeDays: 92,
    );

    $exceso = array_values(array_filter(
        $summary->findings,
        static fn (ComplianceFinding $f): bool => $f->rule->value === 'daily_excess',
    ));

    expect($exceso)->toHaveCount(1)
        ->and($exceso[0]->workDate)->toBe('2026-03-10')
        ->and($exceso[0]->measuredMinutes)->toBe(13 * 60)
        // Y el enlace lleva a la incidencia que SI existe.
        ->and($exceso[0]->incident?->id)->toBe($incidencias[0]->id)
        ->and($exceso[0]->incident?->status)->toBe('open');
})->group('RN-08', 'RN-11', 'RF-PA-06');

it('con el fichaje de pausa activado, la bandeja y la vista cuentan RN-12 igual', function (): void {
    // RF-AT-12 y decision 8 de la ficha 3.5. La propiedad que la prueba anterior
    // describe en negativo, ahora en positivo: **activar el ajuste hace que los
    // dos lados empiecen a contarla a la vez**, sin reprocesar nada y sin tocar
    // codigo. Si uno de los dos se hubiera quedado con la suspension cableada,
    // aqui divergirian.
    $escenario = escenarioComparadoDeCumplimiento();

    DB::table('installation_settings')->updateOrInsert(
        ['key' => 'ATTENDANCE_BREAK_CLOCKING'],
        ['value' => '"enabled"', 'updated_at' => '2026-03-31 04:30:00+00'],
    );

    // `scoped()`: la memoria por peticion muere con ella en produccion, y aqui
    // hay que tirarla a mano o la pasada leeria el valor anterior.
    app()->forgetScopedInstances();

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $vista = paresDeLaVista('missing_break');

    expect($vista)->not->toBe([], 'Con el fichaje de pausa activado, RN-12 tiene que señalar algo.')
        ->and($vista)->toBe(paresDeLaBandeja('missing_break'));

    // Y la pantalla deja de decir que no se evalua: sin esto, el panel seguiria
    // explicando al cliente por que no ve avisos de pausas que si se estan
    // abriendo.
    $summary = app(ReadComplianceSummary::class)->handle(
        new ComplianceSummaryCriteria(
            scope: AccessScope::unrestricted(),
            from: '2026-03-01',
            to: '2026-03-31',
        ),
        maxRangeDays: 92,
    );

    foreach ($summary->rules as $regla) {
        if ($regla->rule->requirement() === 'RN-12') {
            expect($regla->evaluated)->toBeTrue()
                ->and($regla->suspensionReason)->toBeNull();

            return;
        }
    }

    throw new RuntimeException('La vista ya no publica RN-12 en meta.rules.');
})->group('RN-12', 'RF-AT-12', 'RF-PA-06');
