<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Domain\Exception\WorkDateOutsideEmployment;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\CorrectAbsenceCommand;
use App\Modules\Workforce\Application\Command\RegisterAbsenceCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\UseCase\ApplyAbsenceImport;
use App\Modules\Workforce\Application\UseCase\CorrectAbsenceHandler;
use App\Modules\Workforce\Application\UseCase\PlanAbsenceImport;
use App\Modules\Workforce\Application\UseCase\RegisterAbsenceHandler;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportOutcome;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\InterleavingEmployeeDirectory;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\EmployeeWriteInOtherSession;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\InterleavingEmployeeRepository;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **UN TRAMO O UNA AUSENCIA NO QUEDAN DESPUES DEL CESE PORQUE LA BAJA LLEGO
 * ENTRE LA LECTURA Y LA ESCRITURA** (RN-14, RL-04, ADR-046; revision del
 * bloque 17).
 *
 * El alta manual de un tramo y el registro de una ausencia leen la ficha sin
 * candado y fuera de su transaccion. Aqui, justo despues de esa lectura, otra
 * sesion da de baja a la persona con cese el dia anterior a la jornada (o a la
 * ausencia) y confirma. Sin la relectura con la cadena tomada, el tramo o la
 * ausencia quedaban escritos despues del cese. Con ella, el caso de uso ve la
 * baja, responde `422` y deshace todo: ni tramo, ni correccion, ni ausencia, ni
 * asiento.
 *
 * `CommittedDatabase`: la otra sesion es otro proceso.
 */

uses(CommittedDatabase::class);

const RECHECK_AFTER_AUDIT_NOW = '2026-10-02 10:00:00';

/** Un dia antes de la jornada: la baja deja la jornada fuera del periodo de empleo. */
const RECHECK_AFTER_AUDIT_TERMINATED_ON = '2026-10-01';

beforeEach(function (): void {
    FrozenTime::at(RECHECK_AFTER_AUDIT_NOW);
});

afterEach(function (): void {
    // Bloque 17: ninguna sesion de otro proceso sobrevive a la prueba; si
    // quedara alguna, llenaria `max_connections` para la siguiente.
    ChildSessions::waitUntilGone();
});

function personaParaLaRelectura(): string
{
    return WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la relectura'));
}

it('deshace el alta de un tramo si la baja confirmo entre la lectura y el asiento', function (): void {
    $persona = personaParaLaRelectura();
    $responsable = ManagementUsers::withRole(UserRole::RRHH);
    $baja = EmployeeWriteInOtherSession::offboard($persona, RECHECK_AFTER_AUDIT_TERMINATED_ON, RECHECK_AFTER_AUDIT_NOW);
    app()->instance(EmployeeDirectory::class, new InterleavingEmployeeDirectory(
        app(EmployeeDirectory::class),
        $persona,
        $baja->run(...),
    ));

    expect(fn () => app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $persona,
        workDate: '2026-10-02',
        clockedInAt: new DateTimeImmutable('2026-10-02T06:00:00Z'),
        clockedOutAt: new DateTimeImmutable('2026-10-02T09:00:00Z'),
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $responsable->id,
    )))->toThrow(WorkDateOutsideEmployment::class);

    expect($baja->outcome())->toBe(EmployeeWriteInOtherSession::COMMITTED)
        ->and(DB::table('employees')->where('uuid', $persona)->value('status'))->toBe('terminated')
        ->and(DB::table('shift_entries')->count())->toBe(0)
        ->and(DB::table('shift_corrections')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'shift_entry.created')->count())->toBe(0);
})->group('RN-14', 'RF-PA-04', 'RL-04');

it('deshace el registro de una ausencia si la baja confirmo entre la lectura y el asiento', function (): void {
    $persona = personaParaLaRelectura();
    $baja = EmployeeWriteInOtherSession::offboard($persona, RECHECK_AFTER_AUDIT_TERMINATED_ON, RECHECK_AFTER_AUDIT_NOW);
    app()->instance(EmployeeRepository::class, new InterleavingEmployeeRepository(
        app(EmployeeRepository::class),
        $persona,
        $baja->run(...),
        onPlainRead: true,
    ));

    expect(fn () => app(RegisterAbsenceHandler::class)->handle(new RegisterAbsenceCommand(
        employeeUuid: $persona,
        type: AbsenceType::Vacation,
        startsOn: '2026-10-02',
        endsOn: '2026-10-05',
    )))->toThrow(InvalidAbsencePeriod::class);

    expect($baja->outcome())->toBe(EmployeeWriteInOtherSession::COMMITTED)
        ->and(DB::table('absences')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'absence.%')->count())->toBe(0);
})->group('RN-14', 'RF-GP-04', 'RL-04');

it('deshace la correccion de una ausencia que la baja dejaria despues del cese', function (): void {
    $persona = personaParaLaRelectura();
    $original = app(RegisterAbsenceHandler::class)->handle(new RegisterAbsenceCommand(
        employeeUuid: $persona,
        type: AbsenceType::Vacation,
        startsOn: '2026-09-21',
        endsOn: '2026-09-22',
    ));
    expect($original)->not->toBeNull();
    $asientos = DB::table('audit_log')->where('action', 'like', 'absence.%')->count();

    $baja = EmployeeWriteInOtherSession::offboard($persona, RECHECK_AFTER_AUDIT_TERMINATED_ON, RECHECK_AFTER_AUDIT_NOW);
    app()->instance(EmployeeRepository::class, new InterleavingEmployeeRepository(
        app(EmployeeRepository::class),
        $persona,
        $baja->run(...),
        onPlainRead: true,
    ));

    expect(fn () => app(CorrectAbsenceHandler::class)->handle(new CorrectAbsenceCommand(
        absenceUuid: (string) $original?->uuid,
        reason: 'Se movieron las vacaciones.',
        startsOn: '2026-10-02',
        endsOn: '2026-10-05',
    )))->toThrow(InvalidAbsencePeriod::class);

    expect($baja->outcome())->toBe(EmployeeWriteInOtherSession::COMMITTED)
        ->and(DB::table('absences')->count())->toBe(1)
        ->and(DB::table('absences')->value('status'))->toBe('active')
        ->and(DB::table('audit_log')->where('action', 'like', 'absence.%')->count())->toBe($asientos);
})->group('RN-14', 'RF-GP-04', 'RN-13', 'RL-04');

it('deshace la importacion de ausencias si la baja de la persona de la primera fila confirmo entre medias', function (): void {
    $persona = personaParaLaRelectura();
    $fichero = ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta'],
        [[EmployeePins::codeOf($persona), 'vacaciones', '2026-10-02', '2026-10-05']],
    ));
    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.absence_import.column_aliases');
    $informe = app(PlanAbsenceImport::class)->handle((string) $fichero->getRealPath(), 500, $aliases);
    expect($informe->countOf(AbsenceImportOutcome::CREATE))->toBe(1);

    $baja = EmployeeWriteInOtherSession::offboard($persona, RECHECK_AFTER_AUDIT_TERMINATED_ON, RECHECK_AFTER_AUDIT_NOW);
    app()->instance(EmployeeRepository::class, new InterleavingEmployeeRepository(
        app(EmployeeRepository::class),
        $persona,
        $baja->run(...),
        onPlainRead: true,
    ));

    expect(fn () => app(ApplyAbsenceImport::class)->handle($informe, null))->toThrow(InvalidAbsencePeriod::class);

    expect($baja->outcome())->toBe(EmployeeWriteInOtherSession::COMMITTED)
        ->and(DB::table('absences')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'absence.%')->count())->toBe(0);
})->group('RN-14', 'RF-GP-04', 'RL-04');
