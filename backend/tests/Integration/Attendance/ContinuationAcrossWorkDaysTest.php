<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Command\CorrectShiftEntryCommand;
use App\Modules\Attendance\Application\Command\VoidShiftEntryCommand;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\VoidShiftEntryHandler;
use App\Modules\Attendance\Domain\Exception\EntryDoesNotContinueWorkDay;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RN-05 FRENTE A LAS OTRAS JORNADAS DE LA PERSONA (2.3.0).
 *
 * El agregado mide la cota de continuacion con sus propios tramos. Si entre la
 * salida anterior y la entrada de un tramo de otro dia civil hay un tramo
 * vigente de OTRA jornada, la jornada ya se interrumpio: el tramo no la continua
 * aunque quede dentro de la cota. Lo pregunta el caso de uso a
 * `WorkDayRepository::hasEntryOfAnotherWorkDayWithin()`.
 *
 * Escenario, en hora de Madrid (CET): jornada del 14 con un tramo de 22:00 a
 * 00:00; el quiosco abre la jornada del 15 de 00:30 a 04:00; un responsable da
 * de alta en la jornada del 14 un tramo de 05:00 a 07:00 del 15. Son 5 h desde
 * la salida de las 00:00, menos que la cota de 12 h, pero la jornada del 15 se
 * interpuso.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: string, user: int, noche: string}
 */
function interrupcionEscenario(bool $conJornadaDelQuince): array
{
    $site = WorkforceFixtures::site('Hotel de la jornada interrumpida');
    $employee = WorkforceFixtures::employee($site);
    $repositorio = app(WorkDayRepository::class);

    $catorce = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $noche = $catorce->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    // Termina en pausa: sin otra jornada entre medias, la vuelta la continuaria.
    $catorce->clockOut(Instants::inMadrid('2026-03-15 00:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard(), ClockingAction::BREAK_START);
    $repositorio->save($catorce);

    if ($conJornadaDelQuince) {
        $quince = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-15', Instants::madrid()));
        $quince->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-15 00:30'), ScanOrigin::QR_KIOSK);
        $quince->clockOut(Instants::inMadrid('2026-03-15 04:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
        $repositorio->save($quince);
    }

    return [
        'site' => $site,
        'employee' => $employee,
        'user' => ManagementUsers::withRole(UserRole::RRHH)->id,
        'noche' => $noche->uuid(),
    ];
}

/**
 * @param  array{site: int, employee: string, user: int, noche: string}  $escenario
 */
function interrupcionAlta(array $escenario): void
{
    app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $escenario['employee'],
        workDate: '2026-03-14',
        clockedInAt: Instants::inMadrid('2026-03-15 05:00'),
        clockedOutAt: Instants::inMadrid('2026-03-15 07:00'),
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $escenario['user'],
    ));
}

it('rechaza el alta en la jornada del 14 si la jornada del 15 se interpuso, sin escribir nada', function (): void {
    $escenario = interrupcionEscenario(true);
    $antes = [
        DB::table('shift_entries')->count(),
        DB::table('shift_corrections')->count(),
        DB::table('audit_log')->count(),
        DB::table('daily_totals')->count(),
    ];

    try {
        interrupcionAlta($escenario);
        throw new RuntimeException('Se esperaba EntryDoesNotContinueWorkDay.');
    } catch (EntryDoesNotContinueWorkDay $rechazo) {
        expect($rechazo->mark->value)->toBe('clocked_in_at')
            ->and($rechazo->translationKey)->toBe('attendance.errors.entry_does_not_continue_work_day')
            ->and($rechazo->getMessage())->toContain('previous mark 2026-03-14T23:00:00+00:00');
    }

    expect([
        DB::table('shift_entries')->count(),
        DB::table('shift_corrections')->count(),
        DB::table('audit_log')->count(),
        DB::table('daily_totals')->count(),
    ])->toBe($antes);
})->group('RN-05', 'RF-PA-04');

it('admite el mismo alta si ninguna otra jornada se interpuso', function (): void {
    $escenario = interrupcionEscenario(false);

    interrupcionAlta($escenario);

    expect(DB::table('shift_entries')->where('work_date', '2026-03-14')->count())->toBe(2);
})->group('RN-05', 'RF-PA-04');

it('rechaza mover con una correccion un tramo de la jornada del 14 detras de la jornada del 15', function (): void {
    // La misma pregunta en la correccion: mover el tramo de la tarde a las
    // 05:00 del 15 queda a 5 h de la salida de la noche, dentro de la cota,
    // pero detras de la jornada del 15.
    $escenario = interrupcionEscenario(true);

    // Un segundo tramo en la jornada del 14, del mismo dia, para poder moverlo.
    app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $escenario['employee'],
        workDate: '2026-03-14',
        clockedInAt: Instants::inMadrid('2026-03-14 18:00'),
        clockedOutAt: Instants::inMadrid('2026-03-14 19:00'),
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $escenario['user'],
    ));
    $tarde = DB::table('shift_entries')
        ->where('work_date', '2026-03-14')
        ->where('clock_in_source', 'manual_admin')
        ->value('uuid');
    \assert(\is_string($tarde));

    expect(fn () => app(CorrectShiftEntryHandler::class)->handle(new CorrectShiftEntryCommand(
        shiftEntryUuid: $tarde,
        clockedInAt: Instants::inMadrid('2026-03-15 05:00'),
        clockedOutAt: Instants::inMadrid('2026-03-15 07:00'),
        reason: CorrectionReason::of(CorrectionReasonCode::AJUSTE_ACORDADO_CON_RRHH),
        performedByUserId: $escenario['user'],
    )))->toThrow(EntryDoesNotContinueWorkDay::class);

    expect(DB::table('shift_entries')->where('uuid', $tarde)->value('status'))->toBe('closed');
})->group('RN-05', 'RF-PA-04', 'RN-13');

// --- Anular: el tramo siguiente pasa a medirse contra otro anterior (V23-2-QA-2)

/**
 * Jornada del 14 con tres tramos en pausa: de 22:00 a 00:00, de 02:00 a 03:00
 * del 15 y de 05:00 a 07:00 del 15. Cada uno continua el anterior.
 *
 * Con `$conJornadaDelQuince`, un tramo de la jornada del 15, de 00:30 a 01:30,
 * entra despues en el hueco entre los dos primeros. Es lo que hace un alta
 * tardia o la cola offline, y ningun tramo de la jornada del 14 cambia por ello.
 *
 * @return array{employee: string, user: int, madrugada: string, vuelta: string, quince: ?string}
 */
function interrupcionAlAnularEscenario(bool $conJornadaDelQuince): array
{
    $site = WorkforceFixtures::site('Hotel de la anulacion interrumpida');
    $employee = WorkforceFixtures::employee($site);
    $repositorio = app(WorkDayRepository::class);
    $politica = ClockingPolicyFactory::standard();

    $catorce = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $catorce->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $catorce->clockOut(Instants::inMadrid('2026-03-15 00:00'), ScanOrigin::QR_KIOSK, $politica, ClockingAction::BREAK_START);
    $madrugada = $catorce->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-15 02:00'), ScanOrigin::QR_KIOSK);
    $catorce->clockOut(Instants::inMadrid('2026-03-15 03:00'), ScanOrigin::QR_KIOSK, $politica, ClockingAction::BREAK_START);
    $vuelta = $catorce->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-15 05:00'), ScanOrigin::QR_KIOSK);
    $catorce->clockOut(Instants::inMadrid('2026-03-15 07:00'), ScanOrigin::QR_KIOSK, $politica);
    $repositorio->save($catorce);

    $tramoDelQuince = null;

    if ($conJornadaDelQuince) {
        $quince = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-15', Instants::madrid()));
        $tramoDelQuince = $quince->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-15 00:30'), ScanOrigin::QR_KIOSK)->uuid();
        $quince->clockOut(Instants::inMadrid('2026-03-15 01:30'), ScanOrigin::QR_KIOSK, $politica);
        $repositorio->save($quince);
    }

    return [
        'employee' => $employee,
        'user' => ManagementUsers::withRole(UserRole::RRHH)->id,
        'madrugada' => $madrugada->uuid(),
        'vuelta' => $vuelta->uuid(),
        'quince' => $tramoDelQuince,
    ];
}

function interrupcionAnulacion(string $tramo, int $user): void
{
    app(VoidShiftEntryHandler::class)->handle(new VoidShiftEntryCommand(
        shiftEntryUuid: $tramo,
        reason: CorrectionReason::of(CorrectionReasonCode::ERROR_DE_ESCANEO_DUPLICADO),
        performedByUserId: $user,
    ));
}

it('rechaza anular un tramo si el siguiente quedaria detras de otra jornada, sin escribir nada', function (): void {
    // Sin el tramo de las 02:00, la vuelta de las 05:00 se mediria contra la
    // salida de las 00:00: 5 h, dentro de la cota y tras una pausa. Lo unico que
    // lo impide es el tramo de la jornada del 15, de 00:30 a 01:30.
    $escenario = interrupcionAlAnularEscenario(true);
    $antes = [
        DB::table('shift_corrections')->count(),
        DB::table('audit_log')->count(),
        DB::table('daily_totals')->count(),
    ];

    try {
        interrupcionAnulacion($escenario['madrugada'], $escenario['user']);
        throw new RuntimeException('Se esperaba EntryDoesNotContinueWorkDay.');
    } catch (EntryDoesNotContinueWorkDay $rechazo) {
        expect($rechazo->mark->value)->toBe('clocked_in_at')
            ->and($rechazo->translationKey)->toBe('attendance.errors.next_entry_would_not_continue_work_day')
            ->and($rechazo->concernsNextEntry)->toBeTrue()
            ->and($rechazo->parameters)->toBe(['work_date' => '2026-03-14', 'hours' => '12'])
            ->and($rechazo->getMessage())->toContain('lies between the previous mark 2026-03-14T23:00:00+00:00');
    }

    expect(DB::table('shift_entries')->where('uuid', $escenario['madrugada'])->value('status'))->toBe('closed')
        ->and([
            DB::table('shift_corrections')->count(),
            DB::table('audit_log')->count(),
            DB::table('daily_totals')->count(),
        ])->toBe($antes);
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('admite la misma anulacion si ninguna otra jornada se interpuso', function (): void {
    $escenario = interrupcionAlAnularEscenario(false);

    interrupcionAnulacion($escenario['madrugada'], $escenario['user']);

    expect(DB::table('shift_entries')->where('uuid', $escenario['madrugada'])->value('status'))->toBe('voided');
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('admite la misma anulacion si el tramo interpuesto ya no esta vigente', function (): void {
    $escenario = interrupcionAlAnularEscenario(true);
    interrupcionAnulacion((string) $escenario['quince'], $escenario['user']);

    interrupcionAnulacion($escenario['madrugada'], $escenario['user']);

    expect(DB::table('shift_entries')->where('uuid', $escenario['madrugada'])->value('status'))->toBe('voided');
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('deja arreglar el dato anulando de atras hacia delante aunque otra jornada se interponga', function (): void {
    // Regla de RN-05: anular el ultimo siempre se puede.
    $escenario = interrupcionAlAnularEscenario(true);

    interrupcionAnulacion($escenario['vuelta'], $escenario['user']);
    interrupcionAnulacion($escenario['madrugada'], $escenario['user']);

    expect(DB::table('shift_entries')
        ->whereIn('uuid', [$escenario['vuelta'], $escenario['madrugada']])
        ->where('status', 'voided')
        ->count())->toBe(2);
})->group('RN-05', 'RF-PA-04', 'RN-13');

it('pregunta por tramos de otra jornada que pisan el hueco, no por los de la misma', function (): void {
    $escenario = interrupcionEscenario(true);
    $repositorio = app(WorkDayRepository::class);
    $hueco = new TimeRange(Instants::inMadrid('2026-03-15 00:00'), Instants::inMadrid('2026-03-15 05:00'));
    $catorce = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());
    $quince = WorkDate::fromIsoDate('2026-03-15', Instants::madrid());

    expect($repositorio->hasEntryOfAnotherWorkDayWithin($escenario['employee'], $catorce, $hueco))->toBeTrue()
        // Desde la jornada del 15, el tramo del 15 es «la misma».
        ->and($repositorio->hasEntryOfAnotherWorkDayWithin($escenario['employee'], $quince, $hueco))->toBeFalse()
        // Un hueco que acaba cuando empieza el otro tramo no lo pisa: `[inicio, fin)`.
        ->and($repositorio->hasEntryOfAnotherWorkDayWithin(
            $escenario['employee'],
            $catorce,
            new TimeRange(Instants::inMadrid('2026-03-15 00:00'), Instants::inMadrid('2026-03-15 00:30')),
        ))->toBeFalse();
})->group('RN-05');
