<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ShiftCorrectionLedger;
use App\Modules\Attendance\Application\Port\ShiftEntryHistory;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\Support\Corrections;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\CorrectionFactory;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La correccion contra PostgreSQL de verdad (tarea 1.15, RN-13, RL-04, ADR-026).
 *
 * Contra PostgreSQL y no SQLite porque lo que se prueba aqui **no existe** en
 * SQLite: la restriccion de exclusion `shift_entries_no_overlap`, el indice
 * unico parcial de RN-01 y los `CHECK` de `shift_corrections`. Una suite en
 * memoria daria por buenas estas garantias sin haberlas ejercitado nunca.
 *
 * Lo que se comprueba es exactamente lo que el dominio **no puede** comprobar
 * por si mismo: que la version anterior y la nueva caben a la vez en la tabla,
 * que la proyeccion no cuenta las dos, y que el libro de correcciones queda
 * escrito.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: string, user: int}
 */
function correccionFixture(string $timezone = 'Europe/Madrid'): array
{
    $site = WorkforceFixtures::site('Hotel de correcciones', $timezone);

    return [
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
        'user' => ManagementUsers::withRole(UserRole::RRHH)->id,
    ];
}

/**
 * Una jornada con un tramo cerrado ya persistido, que es el punto de partida de
 * toda correccion.
 *
 * @return array{day: WorkDay, entry: string}
 */
function jornadaCerrada(int $site, string $employee, string $in = '2026-03-14 06:00', string $out = '2026-03-14 14:00'): array
{
    $repositorio = app(WorkDayRepository::class);

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $entrada = $jornada->clockIn(Str::uuid7()->toString(), Instants::utc($in), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::utc($out), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    $repositorio->save($jornada);
    proyectar($jornada);

    return ['day' => $jornada, 'entry' => $entrada->uuid()];
}

/**
 * El proyector, invocado como lo invoca el caso de uso: con los eventos que el
 * agregado emitio, dentro de la misma unidad de trabajo.
 */
function proyectar(WorkDay $jornada): void
{
    $proyector = app(DailyTotalsProjector::class);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }
}

it('conserva la fila anterior y la deja fuera del agregado reconstruido', function (): void {
    // RN-13 y RL-04: nada se sobrescribe. La version anterior sigue siendo
    // consultable dentro de dos anos, con sus horas y su version; lo unico que
    // cambia es que deja de ser vigente (ADR-026).
    ['site' => $site, 'employee' => $employee] = correccionFixture();
    ['day' => $jornada, 'entry' => $original] = jornadaCerrada($site, $employee);

    $repositorio = app(WorkDayRepository::class);
    $nuevo = Str::uuid7()->toString();

    $jornada->correctEntry(
        $original,
        $nuevo,
        ShiftTimes::closed(Instants::utc('2026-03-14 06:00'), Instants::utc('2026-03-14 13:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $repositorio->save($jornada);

    // La fila vieja SIGUE en la tabla, con sus horas intactas.
    $anterior = DB::table('shift_entries')->where('uuid', $original)->first();

    expect($anterior)->not->toBeNull()
        ->and($anterior?->status)->toBe(ShiftEntryStatus::SUPERSEDED->value)
        ->and($anterior?->version)->toBe(1)
        ->and($anterior?->duration_minutes)->toBe(480);

    // Y apunta a la nueva: es lo que hace recorrible el historico (RL-04).
    $reemplazo = DB::table('shift_entries')->where('uuid', $nuevo)->first();

    expect($anterior?->superseded_by_id)->toBe($reemplazo?->id)
        ->and($reemplazo?->version)->toBe(2)
        ->and($reemplazo?->duration_minutes)->toBe(450);

    // El agregado reconstruido NO la incluye: es historico, no jornada.
    $recargada = $repositorio->findWorkDayFor($employee, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));

    expect($recargada?->shiftCount())->toBe(1)
        ->and($recargada?->entries()[0]->uuid())->toBe($nuevo);
})->group('RN-13', 'RL-04');

it('corrige un tramo cerrado sin violar la restriccion de exclusion', function (): void {
    // ADR-026. Es la prueba que justifica que `superseded` exista: si la version
    // anterior siguiera siendo vigente para PostgreSQL, la nueva pisaria su
    // intervalo y `shift_entries_no_overlap` abortaria la correccion entera. El
    // orden de escritura del repositorio —retirados primero— es lo que lo evita.
    ['site' => $site, 'employee' => $employee] = correccionFixture();
    ['day' => $jornada, 'entry' => $original] = jornadaCerrada($site, $employee);

    $repositorio = app(WorkDayRepository::class);

    // Marcas que SOLAPAN con las de la version anterior: si aquella contara, la
    // restriccion saltaria.
    $jornada->correctEntry(
        $original,
        Str::uuid7()->toString(),
        ShiftTimes::closed(Instants::utc('2026-03-14 06:15'), Instants::utc('2026-03-14 14:15')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $repositorio->save($jornada);
    proyectar($jornada);

    // Y la proyeccion no duplica minutos: 480 corregidos a 480, no 960 (RN-06).
    $total = DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employee))
        ->where('work_date', '2026-03-14')
        ->value('total_minutes');

    expect($total)->toBe(480)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-02', 'RN-06', 'RN-13');

it('anular libera el hueco y deja meter otro tramo en el mismo horario', function (): void {
    // RN-02: la restriccion de exclusion excluye los `voided`, asi que el hueco
    // queda libre de verdad. Es el caso del doble escaneo que produjo dos tramos
    // donde solo hubo uno: se anula el sobrante y se registra el correcto.
    ['site' => $site, 'employee' => $employee] = correccionFixture();
    ['day' => $jornada, 'entry' => $original] = jornadaCerrada($site, $employee);

    $repositorio = app(WorkDayRepository::class);

    $jornada->voidEntry($original, CorrectionFactory::standard());
    $repositorio->save($jornada);
    proyectar($jornada);

    // El dia se queda a cero: el tramo anulado no cuenta (RN-06).
    $total = DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employee))
        ->where('work_date', '2026-03-14')
        ->value('total_minutes');

    expect($total)->toBe(0);

    // Y el hueco admite exactamente las mismas horas, que es lo que la
    // restriccion habria rechazado si el anulado siguiera contando.
    $nueva = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $nueva->addEntry(
        Str::uuid7()->toString(),
        ShiftTimes::closed(Instants::utc('2026-03-14 06:00'), Instants::utc('2026-03-14 14:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $repositorio->save($nueva);

    expect(DB::table('shift_entries')->count())->toBe(2);
})->group('RN-02', 'RN-06');

it('escribe la fila del libro de correcciones con el antes y el despues', function (): void {
    // RN-13: la correccion conserva la version anterior CON su autor, momento y
    // motivo. Sin esta fila, el registro dice que las horas cambiaron y no dice
    // por que, que es lo unico para lo que `shift_corrections` existe.
    ['site' => $site, 'employee' => $employee, 'user' => $usuario] = correccionFixture();
    ['day' => $jornada, 'entry' => $original] = jornadaCerrada($site, $employee);

    $nuevo = Str::uuid7()->toString();

    $jornada->correctEntry(
        $original,
        $nuevo,
        ShiftTimes::closed(Instants::utc('2026-03-14 06:00'), Instants::utc('2026-03-14 13:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::new()->by($usuario)->at('2026-03-16 09:00')->build(),
        ClockingPolicyFactory::standard(),
    );

    app(WorkDayRepository::class)->save($jornada);
    app(ShiftCorrectionLedger::class)->record(Corrections::in($jornada->releaseEvents()));

    $fila = DB::table('shift_corrections')->first();
    $reemplazo = DB::table('shift_entries')->where('uuid', $nuevo)->value('id');

    expect($fila)->not->toBeNull()
        // Apunta a la version NUEVA: es la que la accion produjo.
        ->and($fila?->shift_entry_id)->toBe($reemplazo)
        ->and($fila?->performed_by_user_id)->toBe($usuario)
        ->and($fila?->action)->toBe('modified')
        ->and($fila?->reason_code)->toBe('OLVIDO_FICHAJE_SALIDA')
        ->and($fila?->reason_text)->toBeNull();

    /** @var array{version: int, clocked_out_at: string, worked_minutes: int} $antes */
    $antes = json_decode((string) $fila?->before, true, 512, JSON_THROW_ON_ERROR);
    /** @var array{version: int, clocked_out_at: string, worked_minutes: int} $despues */
    $despues = json_decode((string) $fila?->after, true, 512, JSON_THROW_ON_ERROR);

    expect($antes['version'])->toBe(1)
        ->and($antes['clocked_out_at'])->toBe('2026-03-14T14:00:00.000000Z')
        ->and($antes['worked_minutes'])->toBe(480)
        ->and($despues['version'])->toBe(2)
        ->and($despues['clocked_out_at'])->toBe('2026-03-14T13:30:00.000000Z')
        ->and($despues['worked_minutes'])->toBe(450);
})->group('RL-04', 'RN-13');

it('apunta la anulacion al tramo que termino y sin valor posterior', function (): void {
    // ADR-026: anular no crea version nueva, asi que la fila del libro apunta a
    // la version que TERMINO y su `after` es nulo. La restriccion
    // `shift_corrections_chk_shape_by_action` lo exige en el esquema: una
    // anulacion con `after` no describe nada que pueda haber pasado.
    ['site' => $site, 'employee' => $employee, 'user' => $usuario] = correccionFixture();
    ['day' => $jornada, 'entry' => $original] = jornadaCerrada($site, $employee);

    $jornada->voidEntry($original, CorrectionFactory::new()->by($usuario)->build());

    app(WorkDayRepository::class)->save($jornada);
    app(ShiftCorrectionLedger::class)->record(Corrections::in($jornada->releaseEvents()));

    $fila = DB::table('shift_corrections')->first();
    $anulado = DB::table('shift_entries')->where('uuid', $original)->value('id');

    expect($fila?->action)->toBe('voided')
        ->and($fila?->shift_entry_id)->toBe($anulado)
        ->and($fila?->after)->toBeNull()
        ->and($fila?->before)->not->toBeNull();

    // Y el tramo anulado NO apunta a ninguna version posterior: no hay una
    // version posterior de un hecho que no ocurrio.
    expect(DB::table('shift_entries')->where('uuid', $original)->value('superseded_by_id'))->toBeNull();
})->group('RN-13', 'RL-04');

it('el esquema rechaza un OTROS sin explicacion suficiente', function (): void {
    // Anexo C. El objeto de valor lo hace inconstruible en PHP; esta restriccion
    // lo hace imposible tambien para una importacion o un script de migracion de
    // datos, que es la unica via por la que una fila asi podria aparecer. En un
    // registro con valor probatorio, la integridad no puede depender solo del
    // codigo de aplicacion (doc 02 §3.2).
    ['site' => $site, 'employee' => $employee, 'user' => $usuario] = correccionFixture();
    ['entry' => $original] = jornadaCerrada($site, $employee);

    $id = DB::table('shift_entries')->where('uuid', $original)->value('id');

    // Cada intento va en su propia transaccion anidada —un `SAVEPOINT`— para que
    // el rechazo de PostgreSQL no deje abortada la transaccion de la prueba y
    // haga fallar a los siguientes por arrastre.
    $insertar = static fn (?string $texto): bool => DB::transaction(
        static fn (): bool => DB::table('shift_corrections')->insert([
            'shift_entry_id' => $id,
            'performed_by_user_id' => $usuario,
            'action' => 'voided',
            'before' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'after' => null,
            'reason_code' => 'OTROS',
            'reason_text' => $texto,
            'created_at' => '2026-03-16 09:00:00+00',
        ]),
    );

    // Diecinueve caracteres: uno menos del minimo.
    expect(static fn (): bool => $insertar(str_repeat('a', 19)))
        ->toThrow(QueryException::class);

    // Y veinte espacios tampoco: se cuenta sobre el texto recortado.
    expect(static fn (): bool => $insertar(str_repeat(' ', 25)))
        ->toThrow(QueryException::class);

    expect($insertar('Acordado con la persona en reunion del 16'))->toBeTrue();
})->group('RF-PA-04', 'RN-13');

it('distingue el tramo que nunca existio del que ya no es vigente', function (): void {
    // ADR-035: es lo que separa un 404 de un 409 en el borde HTTP. Los dos casos
    // devuelven `null` desde el repositorio —en ninguno hay jornada que corrija
    // ese tramo— y solo el historico sabe cual es cual.
    ['site' => $site, 'employee' => $employee] = correccionFixture();
    ['day' => $jornada, 'entry' => $original] = jornadaCerrada($site, $employee);

    $historico = app(ShiftEntryHistory::class);

    expect($historico->isRetired($original))->toBeFalse()
        ->and($historico->isRetired(Str::uuid7()->toString()))->toBeFalse();

    $jornada->voidEntry($original, CorrectionFactory::standard());
    app(WorkDayRepository::class)->save($jornada);

    expect($historico->isRetired($original))->toBeTrue()
        ->and(app(WorkDayRepository::class)->findWorkDayOfShiftEntry($original))->toBeNull();
})->group('RN-13', 'RF-PA-04');

it('encuentra la jornada de un tramo sin preguntar por su fecha', function (): void {
    // RF-PA-04: la correccion empieza por el identificador del tramo, no por la
    // fecha. Y con un turno que cruza la medianoche importa: la jornada es la del
    // dia de INICIO, y buscar por la fecha civil de la salida abriria otra
    // (RN-05, ADR-006, regla dura 4).
    ['site' => $site, 'employee' => $employee] = correccionFixture();

    $repositorio = app(WorkDayRepository::class);
    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $entrada = $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($jornada);

    $encontrada = $repositorio->findWorkDayOfShiftEntry($entrada->uuid());

    expect($encontrada?->workDate()->isoDate)->toBe('2026-03-14')
        ->and($encontrada?->shiftCount())->toBe(1)
        ->and($encontrada?->employeeUuid())->toBe($employee);
})->group('RN-05', 'RF-AT-08', 'RF-PA-04');
