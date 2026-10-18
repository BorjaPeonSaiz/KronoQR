<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Command\CorrectShiftCommand;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\BreakFixtures;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `shift_entries.closed_by` (RN-05, 2.3.0): la migracion rellena lo que ya hay
 * desde los escaneos, por estirpe, y su `down()` la retira sin dejar nada.
 *
 * El *backfill* es la misma consulta que el detalle de jornada
 * (`DatabaseWorkDayJournalReader::clockingMarks()`): un tramo cerrado por el
 * escaneo «Pausa» —o cuya version anterior lo fue— termino en pausa; el resto de
 * cerrados, tambien los escritos a mano, en salida; los abiertos, en nada.
 *
 * `CommittedDatabase`: `migrate` corre en otro proceso.
 */

uses(CommittedDatabase::class);

const CLOSED_BY_MIGRATION = '2026_10_17_100000_add_closed_by_to_shift_entries';

/** Los pasos de `migrate:rollback` que dejan el esquema justo antes de la migracion. */
function closedByStepsBack(): int
{
    /** @var list<string> $aplicadas */
    $aplicadas = DB::connection(config()->string('database.migrations.connection'))
        ->table('migrations')->orderBy('id')->pluck('migration')
        ->map(static fn (mixed $name): string => is_string($name) ? $name : '')
        ->values()->all();

    $posicion = array_search(CLOSED_BY_MIGRATION, $aplicadas, true);

    expect($posicion)->toBeInt('La migracion de closed_by no esta aplicada.');

    /** @var int $posicion */
    return \count($aplicadas) - $posicion;
}

function closedByOf(string $uuid): ?string
{
    $value = DB::table('shift_entries')->where('uuid', $uuid)->value('closed_by');

    return is_string($value) ? $value : null;
}

function closedByColumnExists(): bool
{
    return DB::table('information_schema.columns')
        ->where('table_name', 'shift_entries')
        ->where('column_name', 'closed_by')
        ->exists();
}

it('rellena closed_by desde los escaneos por estirpe y su down() la retira', function (): void {
    $site = WorkforceFixtures::site('Hotel del cierre');
    $employee = WorkforceFixtures::employee($site);
    $user = ManagementUsers::withRole(UserRole::RRHH)->id;

    // Pausa y vuelta escaneadas: el primer tramo lo cerro «Pausa», el segundo
    // una salida.
    $pausa = BreakFixtures::dayWithBreak($site, $employee, '2026-03-14', '2026-03-14 08:00', '2026-03-14 12:00', '2026-03-14 12:30', '2026-03-14 16:00');

    // Se corrige la salida del tramo de la pausa: la version nueva no tiene
    // escaneos, pero su estirpe si.
    $corregido = app(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $pausa['first'],
        clockedInAt: null,
        clockedOutAt: Instants::inMadrid('2026-03-14 11:55'),
        reason: CorrectionReason::of(CorrectionReasonCode::AJUSTE_ACORDADO_CON_RRHH),
        performedByUserId: $user,
    ));

    // Un tramo escrito a mano y cerrado, y otro abierto.
    $manual = app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $employee,
        workDate: '2026-03-16',
        clockedInAt: Instants::inMadrid('2026-03-16 08:00'),
        clockedOutAt: Instants::inMadrid('2026-03-16 14:00'),
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $user,
    ));
    $abierto = app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $employee,
        workDate: '2026-03-17',
        clockedInAt: Instants::inMadrid('2026-03-17 08:00'),
        clockedOutAt: null,
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $user,
    ));

    $name = config()->string('database.migrations.connection');

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.closedByStepsBack());

        expect($back)->toBe(0, $backOutput)
            ->and(closedByColumnExists())->toBeFalse()
            ->and(DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'shift_entries_closed_by_default'"))->toBe(0)
            ->and(DB::scalar("SELECT pg_get_functiondef('public.shift_entries_guard_transition()'::regprocedure)"))
            ->not->toContain('closed_by');
    } finally {
        // Un tramo por lote: el relleno recorre varios lotes y su condicion de
        // parada, como en una instalacion grande.
        config(['database.closed_by_backfill_batch' => 1]);
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(closedByOf($corregido->shiftEntryUuid))->toBe('break_start')
        // La version sustituida tambien: lo cerro el propio escaneo.
        ->and(closedByOf($pausa['first']))->toBe('break_start')
        ->and(closedByOf($pausa['second']))->toBe('clock_out')
        ->and(closedByOf($manual->shiftEntryUuid))->toBe('clock_out')
        ->and(closedByOf($abierto->shiftEntryUuid))->toBeNull()
        ->and(DB::scalar("SELECT pg_get_functiondef('public.shift_entries_guard_transition()'::regprocedure)"))
        ->toContain('closed_by')
        // Cada lote desactiva la guarda dentro de su transaccion: al terminar
        // tiene que estar activa.
        ->and(DB::scalar("SELECT tgenabled FROM pg_trigger WHERE tgname = 'shift_entries_guard_transition'"))->toBe('O')
        ->and(DB::table('shift_entries')->whereNotNull('clocked_out_at')->whereNull('closed_by')->count())->toBe(0);
})->group('RN-05', 'RF-PA-04', 'RL-04');

it('pone clock_out a una salida escrita sin cierre y no deja un cierre sin salida', function (): void {
    $site = WorkforceFixtures::site('Hotel del cierre por defecto');
    $employee = WorkforceFixtures::employee($site);
    $user = ManagementUsers::withRole(UserRole::RRHH)->id;
    $tramo = app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $employee,
        workDate: '2026-03-18',
        clockedInAt: Instants::inMadrid('2026-03-18 08:00'),
        clockedOutAt: Instants::inMadrid('2026-03-18 12:00'),
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $user,
    ));

    // La version anterior del producto escribe la salida sin `closed_by`: el
    // trigger de la fase expand la completa.
    $id = DB::table('shift_entries')->where('uuid', $tramo->shiftEntryUuid)->value('id');
    $copia = (array) DB::table('shift_entries')->where('id', $id)->first();
    unset($copia['id'], $copia['closed_by']);
    $copia['uuid'] = '01a0b349-0000-7000-8000-000000000001';
    $copia['clocked_in_at'] = '2026-03-18 14:00:00+00';
    $copia['clocked_out_at'] = '2026-03-18 15:00:00+00';
    $copia['duration_minutes'] = 60;
    DB::table('shift_entries')->insert($copia);

    expect(closedByOf('01a0b349-0000-7000-8000-000000000001'))->toBe('clock_out');

    // Un tramo abierto con cierre no puede existir.
    expect(fn () => DB::table('shift_entries')->where('uuid', '01a0b349-0000-7000-8000-000000000001')->update([
        'clocked_out_at' => null,
        'closed_by' => 'break_start',
    ]))->toThrow(QueryException::class);
})->group('RN-05', 'RL-04');

it('el trigger por defecto no rellena ni hace chocar un tramo ya cerrado que el relleno no ha alcanzado', function (): void {
    // Entre la primera y la segunda migracion los tramos cerrados tienen
    // `closed_by` nulo. Un UPDATE que no los cierra —p. ej. `updated_at`— no
    // tiene que rellenarlos (eso es del relleno) ni chocar con la guarda.
    $site = WorkforceFixtures::site('Hotel del relleno pendiente');
    $employee = WorkforceFixtures::employee($site);
    $user = ManagementUsers::withRole(UserRole::RRHH)->id;
    $tramo = app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $employee,
        workDate: '2026-03-18',
        clockedInAt: Instants::inMadrid('2026-03-18 08:00'),
        clockedOutAt: Instants::inMadrid('2026-03-18 12:00'),
        reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
        performedByUserId: $user,
    ))->shiftEntryUuid;

    $name = config()->string('database.migrations.connection');

    try {
        // Deshace el CHECK y el relleno: queda la columna y el trigger.
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.(closedByStepsBack() - 1));
        expect($back)->toBe(0, $backOutput);

        SchemaOwner::bypassingTransitionGuards(static fn (ConnectionInterface $owner): int => $owner->update(
            'UPDATE shift_entries SET closed_by = NULL WHERE uuid = ?',
            [$tramo],
        ));

        // Con el rol de la aplicacion, que es el que tiene la guarda delante.
        DB::table('shift_entries')->where('uuid', $tramo)->update(['updated_at' => '2026-03-20 09:00:00+00']);

        expect(closedByOf($tramo))->toBeNull();
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(closedByOf($tramo))->toBe('clock_out');
})->group('RN-05', 'RL-04');
