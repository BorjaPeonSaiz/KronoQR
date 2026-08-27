<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El repositorio del agregado `WorkDay` contra PostgreSQL de verdad.
 *
 * Contra PostgreSQL y no SQLite porque la mitad de lo que se comprueba aqui no
 * existe en SQLite: `TIMESTAMPTZ` con precision de microsegundos, el indice
 * unico parcial de RN-01 y la restriccion de exclusion de RN-02. Una suite en
 * memoria daria por buenas estas garantias sin haberlas ejercitado nunca.
 *
 * Lo que se comprueba es el **mapeo en los dos sentidos** —que lo que sale del
 * dominio vuelve a entrar igual— y que las restricciones del esquema llegan
 * arriba habladas en el lenguaje del negocio y no como un `SQLSTATE`.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: string}
 */
function jornadaFixture(string $timezone = 'Europe/Madrid'): array
{
    $site = WorkforceFixtures::site('Hotel de jornadas', $timezone);

    return ['site' => $site, 'employee' => WorkforceFixtures::employee($site)];
}

function repositorio(): WorkDayRepository
{
    return app(WorkDayRepository::class);
}

it('guarda una entrada y la vuelve a leer identica', function (): void {
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:02:31.123456'), ScanOrigin::QR_KIOSK);

    $repositorio->save($jornada);

    $recuperada = $repositorio->findOpenWorkDayFor($employee);

    expect($recuperada)->not->toBeNull()
        ->and($recuperada?->shiftCount())->toBe(1)
        ->and($recuperada?->workDate()->isoDate)->toBe('2026-03-14')
        // Precision de microsegundos: la columna es `TIMESTAMPTZ(6)` y no la de
        // serie de Laravel, que redondea al segundo. En un registro con valor
        // legal la hora leida tiene que ser la escrita.
        ->and($recuperada?->openEntry()?->clockedInAt()->format('Y-m-d H:i:s.u'))
        ->toBe('2026-03-14 06:02:31.123456')
        // Y siempre en UTC hacia arriba (regla dura 3), sea cual sea la zona de
        // la sesion de PostgreSQL.
        ->and($recuperada?->openEntry()?->clockedInAt()->getOffset())->toBe(0);
})->group('RF-AT-02', 'RN-04');

it('cierra el tramo abierto y guarda su duracion en minutos', function (): void {
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:02'), ScanOrigin::QR_KIOSK);
    $repositorio->save($jornada);

    $abierta = $repositorio->findOpenWorkDayFor($employee);
    $abierta?->clockOut(Instants::utc('2026-03-14 10:02'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($abierta ?? throw new RuntimeException('No hay jornada abierta.'));

    $fila = DB::table('shift_entries')->where('employee_id', AttendanceFixtures::employeeIdOf($employee))->first();

    expect($fila?->duration_minutes)->toBe(240)
        ->and($fila?->status)->toBe(ShiftEntryStatus::CLOSED->value)
        ->and($fila?->clock_out_source)->toBe(ScanOrigin::QR_KIOSK->value)
        // El repositorio deja de ver la jornada como abierta: es la consulta
        // que decide si el siguiente escaneo abre o cierra turno.
        ->and($repositorio->findOpenWorkDayFor($employee))->toBeNull();
})->group('RF-AT-03', 'RN-06');

it('encuentra la jornada de un turno de noche por su turno abierto, no por la fecha', function (): void {
    // RN-05, ADR-006 y regla dura 4. Es la razon de que el puerto pregunte por
    // el turno ABIERTO: buscar por la fecha del escaneo abriria una jornada
    // nueva a las 06:00 y partiria el turno en dos.
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $repositorio->save($jornada);

    $abierta = $repositorio->findOpenWorkDayFor($employee);

    expect($abierta?->workDate()->isoDate)->toBe('2026-03-14');

    $abierta?->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($abierta ?? throw new RuntimeException('No hay jornada abierta.'));

    $filas = DB::table('shift_entries')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employee))
        ->get();

    // Un solo tramo, de 480 minutos, atribuido al dia 14. Ningun tramo
    // artificial a las 23:59.
    expect($filas)->toHaveCount(1)
        ->and($filas->first()?->duration_minutes)->toBe(480)
        ->and(substr((string) $filas->first()?->work_date, 0, 10))->toBe('2026-03-14');
})->group('RF-AT-08', 'RN-05');

it('mide sobre instantes UTC el dia del cambio de hora de otono', function (): void {
    // El escenario «Cambio de hora de otono» del doc 01 §11: 01:30 CEST a
    // 03:00 CET son 150 minutos reales, no 90. Se escribe en UTC porque es
    // donde esas dos horas no son ambiguas.
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-10-25', Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-10-24 23:30'), ScanOrigin::QR_KIOSK);
    $repositorio->save($jornada);

    $abierta = $repositorio->findOpenWorkDayFor($employee);
    $abierta?->clockOut(Instants::utc('2026-10-25 02:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($abierta ?? throw new RuntimeException('No hay jornada abierta.'));

    $fila = DB::table('shift_entries')->where('employee_id', AttendanceFixtures::employeeIdOf($employee))->first();

    expect($fila?->duration_minutes)->toBe(150);
})->group('RN-09');

it('carga la jornada partida entera y suma sus dos tramos', function (): void {
    // RF-AT-04: N tramos por jornada, sin limite. Es lo que rompe la suposicion
    // de «un empleado, una entrada y una salida al dia».
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();
    $fecha = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());

    $jornada = WorkDay::start($employee, $site, $fecha);
    $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 08:00'), ScanOrigin::QR_KIOSK);
    $repositorio->save($jornada);

    $abierta = $repositorio->findOpenWorkDayFor($employee);
    $abierta?->clockOut(Instants::utc('2026-03-14 12:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($abierta ?? throw new RuntimeException('No hay jornada abierta.'));

    $tarde = $repositorio->findWorkDayFor($employee, $fecha);
    $tarde?->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 16:00'), ScanOrigin::QR_KIOSK);
    $repositorio->save($tarde ?? throw new RuntimeException('No hay jornada.'));

    $cierre = $repositorio->findOpenWorkDayFor($employee);
    $cierre?->clockOut(Instants::utc('2026-03-14 20:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($cierre ?? throw new RuntimeException('No hay jornada abierta.'));

    $final = $repositorio->findWorkDayFor($employee, $fecha);

    expect($final?->shiftCount())->toBe(2)
        ->and($final?->totalWorked()->minutes)->toBe(480);
})->group('RF-AT-04', 'RN-06');

it('no carga los tramos anulados ni los sustituidos', function (): void {
    // ADR-026: son historico y no forman parte del agregado. Cargarlos haria
    // que RN-06 sumara dos versiones del mismo tramo y que la restriccion de
    // exclusion pareciera violada donde no lo esta.
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $employeeId = AttendanceFixtures::employeeIdOf($employee);

    foreach ([['voided', '04:00', '09:00'], ['superseded', '09:30', '10:00'], ['closed', '10:00', '12:00']] as [$estado, $desde, $hasta]) {
        DB::table('shift_entries')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'site_id' => $site,
            'work_date' => '2026-03-14',
            'clocked_in_at' => '2026-03-14 '.$desde.':00+00',
            'clocked_out_at' => '2026-03-14 '.$hasta.':00+00',
            'duration_minutes' => 120,
            'status' => $estado,
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
        ]);
    }

    $jornada = repositorio()->findWorkDayFor($employee, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));

    expect($jornada?->shiftCount())->toBe(1)
        ->and($jornada?->totalWorked()->minutes)->toBe(120);
})->group('RN-06', 'RN-13');

it('traduce la violacion de RN-01 a una excepcion de dominio', function (): void {
    // El indice unico parcial `one_open_shift_per_employee` es la ultima linea
    // de defensa (doc 02 §3.2) y bajo concurrencia SALTA. El caso de uso sabe
    // que hacer con `ShiftAlreadyOpen` —reintentar y resolverlo por
    // anti-rebote—, pero no con un `SQLSTATE 23505`.
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();
    $fecha = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());

    $primera = WorkDay::start($employee, $site, $fecha);
    $primera->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $repositorio->save($primera);

    // Una jornada construida en paralelo, que no vio el tramo de la otra: es
    // exactamente lo que le pasa a la segunda peticion de una carrera.
    $paralela = WorkDay::start($employee, $site, $fecha);
    $paralela->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00:02'), ScanOrigin::QR_KIOSK);

    expect(function () use ($repositorio, $paralela): void {
        $repositorio->save($paralela);
    })->toThrow(ShiftAlreadyOpen::class);
})->group('RN-01');

it('proyecta daily_totals con lo que emite el agregado y cuadra con los tramos', function (): void {
    // RN-06 y regla dura 7 comprobadas donde de verdad importan: la proyeccion
    // frente a sus eventos origen. Son las dos consultas de la reconciliacion
    // de RF-PR-02, y tienen que devolver lo mismo.
    ['site' => $site, 'employee' => $employee] = jornadaFixture();
    $repositorio = repositorio();
    $proyector = app(DailyTotalsProjector::class);

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $repositorio->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }

    $abierta = $repositorio->findOpenWorkDayFor($employee)
        ?? throw new RuntimeException('No hay jornada abierta.');

    $abierta->clockOut(Instants::utc('2026-03-14 14:30'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($abierta);

    foreach ($abierta->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }

    $total = DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employee))
        ->first();

    expect($total?->total_minutes)->toBe(510)
        ->and($total?->shift_count)->toBe(1)
        ->and($total?->has_open_shift)->toBeFalse()
        // Una sola fila por (empleado, jornada): el `ON CONFLICT DO UPDATE`
        // recalcula, no acumula ni duplica.
        ->and(DB::table('daily_totals')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-06', 'RF-PR-02');
