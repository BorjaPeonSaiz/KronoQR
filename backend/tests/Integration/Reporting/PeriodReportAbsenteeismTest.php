<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Domain\Policy\AbsenteeismRule;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El SQL del informe cuenta exactamente lo que dice `AbsenteeismRule`**
 * (RF-GP-04, decision 7 de la ficha 3.10).
 *
 * ## Por que hace falta esta prueba
 *
 * Por lo mismo que la del prorrateo de contratos: la definicion de los tres
 * contadores existe en dos sitios —el enunciado puro de
 * `Reporting\Domain\Policy\AbsenteeismRule` y su traduccion a `FILTER` dentro de
 * `DatabasePeriodReportReader`—, y esa duplicacion esta asumida porque el
 * dominio no puede recorrer dia a dia la plantilla de un hotel. Lo que no puede
 * quedar es sin red: si las dos expresiones divergieran, el informe acusaria de
 * absentismo dias que la regla justifica, y el primero en descubrirlo seria
 * alguien defendiendose de un aviso.
 *
 * ## El caso, con los cuatro tipos de dia a la vez
 *
 * Una semana con una ausencia de tres dias, un festivo del perfil, un dia
 * trabajado y un dia sin nada. Es el escenario minimo en el que los tres
 * contadores valen algo distinto de cero al mismo tiempo.
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $festivos  fechas ISO del calendario del perfil
 * @return array{site: int, employee: string}
 */
function contextoDeAbsentismo(array $festivos = []): array
{
    $site = WorkforceFixtures::site('Hotel de absentismo');

    // Los festivos entran por el perfil de cumplimiento, que es de donde los
    // toma el informe (regla dura 14: el dominio no consulta configuracion, se
    // los pasa `GeneratePeriodReport` por `CompliancePolicyProvider`).
    DB::table('compliance_profiles')
        ->where('is_default', true)
        ->update(['holiday_calendar' => json_encode($festivos, JSON_THROW_ON_ERROR)]);

    return [
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site, null, 'active', 'Youssef', 'Amrani'),
    ];
}

function informeDiarioDeAbsentismo(string $from, string $to): PeriodReport
{
    /** @var GeneratePeriodReport $caso */
    $caso = app(GeneratePeriodReport::class);

    return $caso->handle(
        new PeriodReportQuery(
            scope: AccessScope::unrestricted(),
            range: DateRange::between($from, $to),
            granularity: ReportGranularity::Day,
            grouping: ReportGrouping::Employee,
            departmentId: null,
            employeeUuid: null,
        ),
        maxRangeDays: 92,
        maxRows: 20000,
    );
}

it('cuenta lo mismo que AbsenteeismRule sobre una semana con ausencia, festivo, trabajo y nada', function (): void {
    // Lunes 9 a domingo 15 de marzo de 2026.
    //   · 9  — trabajado.
    //   · 10 — festivo del perfil.
    //   · 11, 12, 13 — baja medica de tres dias.
    //   · 14 — sin nada.
    //   · 15 — sin nada.
    $contexto = contextoDeAbsentismo(['2026-03-10']);

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::absence($contexto['employee'], 'sick_leave', '2026-03-11', '2026-03-13');

    $informe = informeDiarioDeAbsentismo('2026-03-09', '2026-03-15');

    // Lo que el dominio dice de cada dia, calculado aqui a partir de los mismos
    // hechos y **sin mirar el informe**.
    $esperado = [];

    foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13', '2026-03-14', '2026-03-15'] as $dia) {
        $esperado[$dia] = AbsenteeismRule::classify(
            // De alta: `WorkforceFixtures` contrata desde el 2026-01-01 y no cesa.
            employed: true,
            hasActivity: $dia === '2026-03-09',
            coveredByAbsence: AbsenteeismRule::covers('2026-03-11', '2026-03-13', $dia),
            holiday: $dia === '2026-03-10',
        );
    }

    $obtenido = [];

    foreach ($informe->rows as $fila) {
        $obtenido[$fila->isoPeriodStart()] = [
            'absence' => $fila->absenceDays === 1,
            'holiday' => $fila->holidayDays === 1,
            'unjustified' => $fila->unjustifiedAbsenceDays === 1,
        ];
    }

    expect($obtenido)->toBe($esperado);
})->group('RF-GP-04', 'RF-IN-01');

it('agrega el mismo caso en una sola fila sin perder ninguno de los tres', function (): void {
    // La misma semana con granularidad de rango: tres dias de ausencia, un
    // festivo y dos dias sin justificar. La suma de las tres columnas mas el dia
    // trabajado es la semana entera, que es la propiedad que permite leer el
    // informe sin recalcular nada.
    $contexto = contextoDeAbsentismo(['2026-03-10']);

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::absence($contexto['employee'], 'sick_leave', '2026-03-11', '2026-03-13');

    /** @var GeneratePeriodReport $caso */
    $caso = app(GeneratePeriodReport::class);

    $informe = $caso->handle(
        new PeriodReportQuery(
            scope: AccessScope::unrestricted(),
            range: DateRange::between('2026-03-09', '2026-03-15'),
            granularity: ReportGranularity::Range,
            grouping: ReportGrouping::Employee,
            departmentId: null,
            employeeUuid: null,
        ),
        maxRangeDays: 92,
        maxRows: 20000,
    );

    /** @var PeriodReportRow $fila */
    $fila = $informe->rows[0];

    expect($fila->daysInPeriod)->toBe(7)
        ->and($fila->daysWithActivity)->toBe(1)
        ->and($fila->absenceDays)->toBe(3)
        ->and($fila->holidayDays)->toBe(1)
        ->and($fila->unjustifiedAbsenceDays)->toBe(2);

    // La invariante que declara `PeriodReportRow`: el absentismo no justificado
    // es un subconjunto de los dias sin actividad.
    expect($fila->unjustifiedAbsenceDays)->toBeLessThanOrEqual($fila->daysWithoutActivity());
})->group('RF-GP-04', 'RF-IN-01');

it('deja el festivo que cae dentro de una ausencia contado una sola vez, como ausencia', function (): void {
    // Los dos contadores de dias justificados son DISJUNTOS: si el mismo dia
    // sumara en los dos, quien los sumara para restarlos del periodo contaria un
    // dia de mas. Gana la ausencia, porque es lo que el registro afirma de esa
    // persona; el festivo lo tenia todo el centro.
    $contexto = contextoDeAbsentismo(['2026-03-12']);

    PeriodReportFixtures::absence($contexto['employee'], 'vacation', '2026-03-11', '2026-03-13');

    $informe = informeDiarioDeAbsentismo('2026-03-11', '2026-03-13');

    $ausencias = array_sum(array_map(static fn (PeriodReportRow $f): int => $f->absenceDays, $informe->rows));
    $festivos = array_sum(array_map(static fn (PeriodReportRow $f): int => $f->holidayDays, $informe->rows));

    expect($ausencias)->toBe(3)
        ->and($festivos)->toBe(0);
})->group('RF-GP-04');

it('no cuenta las ausencias supersedidas ni las anuladas', function (): void {
    // Regla dura 5: nada se borra. Una ausencia corregida deja su version
    // anterior en la tabla y una anulada sigue en el historico — pero ninguna de
    // las dos justifica ya nada, y contarlas haria que corregir bien una fecha
    // dejara justificados los dias viejos y los nuevos.
    $contexto = contextoDeAbsentismo();

    PeriodReportFixtures::absence($contexto['employee'], 'leave', '2026-03-11', '2026-03-11');

    DB::table('absences')->update(['status' => 'voided', 'voided_at' => (string) now(), 'void_reason' => 'Se registro por error']);

    $informe = informeDiarioDeAbsentismo('2026-03-11', '2026-03-11');

    /** @var PeriodReportRow $fila */
    $fila = $informe->rows[0];

    expect($fila->absenceDays)->toBe(0)
        // Y vuelve a ser lo que era: un dia sin actividad y sin justificar.
        ->and($fila->unjustifiedAbsenceDays)->toBe(1);
})->group('RF-GP-04', 'RN-13');

it('no cuenta como ausencia ni como festivo un dia fuera de la relacion laboral', function (): void {
    // «De alta» es condicion previa de los tres contadores, igual que en
    // `days_without_contract`: un dia posterior al cese no es la ausencia de
    // nadie, es un dia en el que esa persona ya no trabajaba aqui.
    $contexto = contextoDeAbsentismo(['2026-07-06']);

    PeriodReportFixtures::absence($contexto['employee'], 'vacation', '2026-07-01', '2026-07-03');

    // `WorkforceFixtures::terminate()` cierra la relacion el 2026-06-30.
    WorkforceFixtures::terminate($contexto['employee']);

    $informe = informeDiarioDeAbsentismo('2026-07-01', '2026-07-06');

    $totales = [
        'ausencia' => array_sum(array_map(static fn (PeriodReportRow $f): int => $f->absenceDays, $informe->rows)),
        'festivo' => array_sum(array_map(static fn (PeriodReportRow $f): int => $f->holidayDays, $informe->rows)),
        'sin justificar' => array_sum(array_map(static fn (PeriodReportRow $f): int => $f->unjustifiedAbsenceDays, $informe->rows)),
    ];

    expect($totales)->toBe(['ausencia' => 0, 'festivo' => 0, 'sin justificar' => 0]);
})->group('RF-GP-04', 'RN-14');

it('cuenta la ausencia de una persona aunque ese dia haya fichado', function (): void {
    // Una ausencia con fichajes dentro SIGUE SIENDO ausencia: es un hecho
    // registrado por RRHH y el informe lo dice en vez de corregirlo. Lo que no
    // hace es contarlo como absentismo, que es lo que el tercer contador excluye.
    $contexto = contextoDeAbsentismo();

    PeriodReportFixtures::absence($contexto['employee'], 'vacation', '2026-03-11', '2026-03-11');
    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-11', '2026-03-11 09:00', '2026-03-11 13:00');

    $informe = informeDiarioDeAbsentismo('2026-03-11', '2026-03-11');

    /** @var PeriodReportRow $fila */
    $fila = $informe->rows[0];

    expect($fila->absenceDays)->toBe(1)
        ->and($fila->daysWithActivity)->toBe(1)
        ->and($fila->workedMinutes)->toBe(240)
        ->and($fila->unjustifiedAbsenceDays)->toBe(0);
})->group('RF-GP-04');
