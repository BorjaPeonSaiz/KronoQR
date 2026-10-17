<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las transiciones del registro horario las vigila el motor (ADR-057 §2,
 * bloque 7 de la 2.2.1; RL-04, RS-07, RN-13, reglas duras 5 y 6).
 *
 * Contra PostgreSQL de verdad. Las escrituras que la aplicacion puede intentar
 * —las columnas que tiene concedidas— van con `fichaje_app`, la conexion por
 * defecto; las que ya le niega el privilegio de columna van con el propietario,
 * que tambien pasa por el *trigger*: es la segunda barrera por si alguien
 * vuelve a conceder una columna.
 *
 * `CommittedDatabase` y no `RefreshDatabase`, por dos razones: el propietario es
 * otra sesion y no veria los tramos sin confirmar, y la comprobacion diferida
 * «un tramo sustituido apunta a su version nueva» solo corre al confirmar.
 */

uses(CommittedDatabase::class);

/**
 * Un tramo en el estado pedido, escrito por la aplicacion.
 *
 * Los estados finales no se insertan: desde el bloque 7 un tramo nace vigente
 * (`shift_entries_guard_insert`). Se llega a ellos como el dominio, por las
 * transiciones: `voided` anulando uno cerrado, `superseded` retirandolo y
 * encadenandolo con una version siguiente en la hora de despues.
 *
 * @return array{id: int, uuid: string, employee: int}
 */
function transitionsEntry(string $status, int $version = 1, string $workDate = '2026-03-14', ?int $employeeId = null, ?int $hour = null): array
{
    $site = WorkforceFixtures::site();
    $employeeId ??= AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));
    $uuid = Str::uuid7()->toString();
    $final = $status;
    $status = in_array($final, ['superseded', 'voided'], true) ? 'closed' : $final;
    $open = $status === 'open';
    // Cada version en su hueco: la restriccion de exclusion (RN-02) no admite
    // dos tramos vigentes que se pisen.
    $hour ??= 6 + $version;

    $id = (int) DB::table('shift_entries')->insertGetId([
        'uuid' => $uuid,
        'employee_id' => $employeeId,
        'site_id' => $site,
        'work_date' => $workDate,
        'clocked_in_at' => sprintf('%s %02d:00:00+00', $workDate, $hour),
        'clocked_out_at' => $open ? null : sprintf('%s %02d:30:00+00', $workDate, $hour),
        'duration_minutes' => $open ? null : 30,
        'status' => $status,
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $open ? null : 'qr_kiosk',
        'version' => $version,
        'created_at' => $workDate.' 06:00:00+00',
        'updated_at' => $workDate.' 06:00:00+00',
    ]);

    if ($final === 'voided') {
        DB::table('shift_entries')->where('id', $id)->update(['status' => 'voided']);
    }

    if ($final === 'superseded') {
        DB::transaction(static function () use ($id, $version, $workDate, $employeeId, $hour): void {
            DB::table('shift_entries')->where('id', $id)->update(['status' => 'superseded']);

            $replacement = transitionsEntry('closed', $version + 1, $workDate, $employeeId, $hour + 1);

            DB::table('shift_entries')->where('id', $id)->update(['superseded_by_id' => $replacement['id']]);
        });
    }

    return ['id' => $id, 'uuid' => $uuid, 'employee' => $employeeId];
}

/**
 * Ejecuta `$write` en su transaccion y exige `42501`. La transaccion se
 * confirma si no falla, asi que tambien atrapa la comprobacion diferida.
 */
function transitionsExpectDenied(string $what, Closure $write, ?ConnectionInterface $connection = null): void
{
    $connection ??= DB::connection();

    try {
        $connection->transaction(static function () use ($write, $connection): void {
            $write($connection);
        });
    } catch (PDOException $exception) {
        // `QueryException` o, si falla al confirmar, la `PDOException` del
        // `commit()`: la comprobacion diferida salta ahi.
        expect($exception->getCode())->toBe('42501', $what.': '.$exception->getMessage())
            ->and($exception->getMessage())->toContain('ADR-057');

        return;
    }

    Assert::fail('PostgreSQL ha admitido «'.$what.'».');
}

/**
 * @param  array<string, mixed>  $values
 * @return Closure(ConnectionInterface): int
 */
function transitionsUpdate(int $id, array $values): Closure
{
    return static fn (ConnectionInterface $connection): int => $connection->table('shift_entries')
        ->where('id', $id)
        ->update([...$values, 'updated_at' => '2026-03-20 09:00:00+00']);
}

it('corre con el rol de la aplicacion', function (): void {
    /** @var object{role: string} $row */
    $row = DB::selectOne('SELECT current_user AS role');

    expect($row->role)->toBe(Config::string('database.roles.application'));
})->group('RS-07', 'RL-04');

it('el motor rechaza las transiciones de tramo que el dominio no produce', function (string $from, array $values): void {
    $entry = transitionsEntry($from);

    transitionsExpectDenied($from.' con '.json_encode($values), transitionsUpdate($entry['id'], $values));

    // Y la fila sigue como estaba.
    expect(DB::table('shift_entries')->where('id', $entry['id'])->value('status'))->toBe($from);
})->with([
    'reescribir la salida de un tramo cerrado' => ['closed', ['clocked_out_at' => '2026-03-14 07:45:00+00']],
    'reescribir la duracion de un tramo cerrado' => ['closed', ['duration_minutes' => 600]],
    'reescribir el origen de la salida' => ['closed', ['clock_out_source' => 'manual_admin']],
    'reescribir la salida de un tramo anomalo' => ['anomalous', ['clocked_out_at' => '2026-03-14 07:45:00+00', 'duration_minutes' => 45]],
    'reabrir un tramo cerrado' => ['closed', ['status' => 'open', 'clocked_out_at' => null, 'duration_minutes' => null, 'clock_out_source' => null]],
    'pasar un tramo cerrado a anomalo' => ['closed', ['status' => 'anomalous']],
    'pasar un tramo anomalo a cerrado' => ['anomalous', ['status' => 'closed']],
    'sacar un tramo de superseded' => ['superseded', ['status' => 'closed']],
    'sacar un tramo de voided' => ['voided', ['status' => 'closed']],
    'pasar un tramo anulado a sustituido' => ['voided', ['status' => 'superseded']],
    'pasar un tramo sustituido a anulado' => ['superseded', ['status' => 'voided']],
    'cerrar sin hora de salida' => ['open', ['status' => 'closed']],
    'escribir la salida al anular un tramo abierto' => ['open', ['status' => 'voided', 'clocked_out_at' => '2026-03-14 07:30:00+00']],
    'escribir la duracion de un tramo abierto' => ['open', ['duration_minutes' => 30]],
    // RN-05 (2.3.0): como termino un tramo solo se escribe al cerrarlo.
    'reescribir como termino un tramo cerrado' => ['closed', ['closed_by' => 'break_start']],
    'reescribir como termino un tramo anomalo' => ['anomalous', ['closed_by' => 'break_start']],
    'escribir como termino un tramo abierto sin cerrarlo' => ['open', ['closed_by' => 'break_start']],
    'escribir como termino al anular un tramo abierto' => ['open', ['status' => 'voided', 'closed_by' => 'break_start']],
])->group('RS-07', 'RL-04', 'RN-13', 'RN-05');

it('el motor rechaza un superseded_by_id que no es la version siguiente', function (string $case): void {
    $old = transitionsEntry('closed');
    $other = transitionsEntry('closed', 2, '2026-03-15', $old['employee']);

    $target = match ($case) {
        // Otra jornada, aunque sea del mismo empleado y version 2.
        'otra jornada' => $other['id'],
        // Version 2 de la misma jornada, pero de otra persona.
        'otra persona' => transitionsEntry('closed', 2)['id'],
        // La misma jornada y persona, pero no la version siguiente.
        'version que no sigue' => transitionsEntry('closed', 3, '2026-03-14', $old['employee'])['id'],
        default => throw new LogicException($case),
    };

    transitionsExpectDenied('superseded_by_id a '.$case, transitionsUpdate($old['id'], [
        'status' => 'superseded',
        'superseded_by_id' => $target,
    ]));
})->with(['otra jornada', 'otra persona', 'version que no sigue'])->group('RS-07', 'RL-04', 'RN-13');

it('el motor rechaza encadenar fuera de una sustitucion o volver a encadenar', function (): void {
    $old = transitionsEntry('closed');
    $replacement = transitionsEntry('closed', 2, '2026-03-14', $old['employee']);

    transitionsExpectDenied('superseded_by_id sin pasar a superseded', transitionsUpdate($old['id'], [
        'superseded_by_id' => $replacement['id'],
    ]));

    // La sustitucion legitima pasa…
    DB::transaction(static function () use ($old, $replacement): void {
        transitionsUpdate($old['id'], ['status' => 'superseded'])(DB::connection());
        transitionsUpdate($old['id'], ['superseded_by_id' => $replacement['id']])(DB::connection());
    });

    // …y ya no se puede apuntar a otra parte, ni quitar el puntero.
    $another = transitionsEntry('voided', 2, '2026-03-14', $old['employee'], hour: 12);

    transitionsExpectDenied('cambiar superseded_by_id', transitionsUpdate($old['id'], ['superseded_by_id' => $another['id']]));
    transitionsExpectDenied('quitar superseded_by_id', transitionsUpdate($old['id'], ['superseded_by_id' => null]));
})->group('RS-07', 'RL-04', 'RN-13');

it('un tramo sustituido sin version nueva no llega a confirmarse', function (string $from): void {
    // Lo que el privilegio de columna no distinguia: sacar un tramo vigente de
    // la exportacion marcandolo `superseded` sin version que lo sustituya.
    $entry = transitionsEntry($from);

    transitionsExpectDenied('superseded sin superseded_by_id', transitionsUpdate($entry['id'], ['status' => 'superseded']));

    expect(DB::table('shift_entries')->where('id', $entry['id'])->value('status'))->toBe($from);
})->with(['open', 'closed', 'anomalous'])->group('RS-07', 'RL-04', 'RN-13');

it('el propietario tambien pasa por el trigger en las columnas que la aplicacion no tiene', function (string $column, mixed $value): void {
    $entry = transitionsEntry('closed');
    $site = WorkforceFixtures::site();

    transitionsExpectDenied(
        'propietario cambia '.$column,
        static fn (ConnectionInterface $owner): int => $owner->table('shift_entries')->where('id', $entry['id'])->update([
            $column => $value === 'SITE' ? $site : $value,
        ]),
        SchemaOwner::connection(),
    );
})->with([
    'clocked_in_at' => ['clocked_in_at', '2026-03-14 05:00:00+00'],
    'work_date' => ['work_date', '2026-03-13'],
    'clock_in_source' => ['clock_in_source', 'manual_admin'],
    'version' => ['version', 7],
    'uuid' => ['uuid', '01900000-0000-7000-8000-000000000000'],
    'created_at' => ['created_at', '2020-01-01 00:00:00+00'],
])->group('RS-07', 'RL-04', 'RN-13');

it('las transiciones del dominio pasan con el rol de la aplicacion', function (string $from, array $values): void {
    $entry = transitionsEntry($from);

    DB::transaction(transitionsUpdate($entry['id'], $values));

    expect(DB::table('shift_entries')->where('id', $entry['id'])->value('status'))->toBe($values['status'] ?? $from);
})->with([
    'cerrar (open -> closed)' => ['open', ['status' => 'closed', 'clocked_out_at' => '2026-03-14 15:00:00+00', 'duration_minutes' => 480, 'clock_out_source' => 'qr_kiosk']],
    'cerrar con anomalia (open -> anomalous)' => ['open', ['status' => 'anomalous', 'clocked_out_at' => '2026-03-14 07:05:00+00', 'duration_minutes' => 5, 'clock_out_source' => 'pin_kiosk']],
    'anular un abierto (open -> voided)' => ['open', ['status' => 'voided']],
    'anular un cerrado (closed -> voided)' => ['closed', ['status' => 'voided']],
    'anular un anomalo (anomalous -> voided)' => ['anomalous', ['status' => 'voided']],
    'solo updated_at en un anulado' => ['voided', []],
    'reescribir los mismos valores de un cerrado (upsert)' => ['closed', ['status' => 'closed', 'clocked_out_at' => '2026-03-14 07:30:00+00', 'duration_minutes' => 30, 'clock_out_source' => 'qr_kiosk']],
    // RN-05 (2.3.0): el cierre del quiosco por «Pausa».
    'cerrar en pausa (open -> closed, break_start)' => ['open', ['status' => 'closed', 'clocked_out_at' => '2026-03-14 11:00:00+00', 'duration_minutes' => 240, 'clock_out_source' => 'qr_kiosk', 'closed_by' => 'break_start']],
])->group('RS-07', 'RL-04', 'RN-13', 'RN-05');

it('superseder con su version nueva pasa, en el orden de EloquentWorkDayRepository', function (string $from): void {
    $old = transitionsEntry($from);

    // Una transaccion, como `save()`: retirar, insertar la nueva y encadenar.
    // La comprobacion diferida corre al confirmar y la da por buena.
    DB::transaction(static function () use ($old): void {
        transitionsUpdate($old['id'], ['status' => 'superseded'])(DB::connection());

        $replacement = transitionsEntry('closed', 2, '2026-03-14', $old['employee']);

        transitionsUpdate($old['id'], ['superseded_by_id' => $replacement['id']])(DB::connection());
    });

    expect(DB::table('shift_entries')->where('id', $old['id'])->value('status'))->toBe('superseded')
        ->and(DB::table('shift_entries')->where('id', $old['id'])->value('superseded_by_id'))->not->toBeNull();

    // Ya encadenado, tocar solo `updated_at` de la version historica pasa.
    DB::transaction(transitionsUpdate($old['id'], []));
})->with(['open', 'closed', 'anomalous'])->group('RS-07', 'RL-04', 'RN-13');

it('el upsert de EloquentWorkDayRepository sobre un tramo ya cerrado pasa sin cambiar nada', function (): void {
    $entry = transitionsEntry('closed');
    $row = DB::table('shift_entries')->where('id', $entry['id'])->first();

    expect($row)->not->toBeNull();

    // La jornada se guarda entera: los tramos que no cambian se reescriben con
    // sus mismos valores por el `ON CONFLICT DO UPDATE`.
    $values = (array) $row;
    unset($values['id']);

    DB::table('shift_entries')->upsert([$values], ['uuid'], [
        'clocked_out_at', 'duration_minutes', 'status', 'clock_out_source', 'updated_at',
    ]);

    expect(DB::table('shift_entries')->where('id', $entry['id'])->value('status'))->toBe('closed');
})->group('RS-07', 'RL-04', 'RN-13');

// --- Incidencias --------------------------------------------------------------

/**
 * Una incidencia resuelta por la aplicacion, en la forma que deja
 * `DatabaseIncidentLedger::recordResolution`.
 */
function transitionsResolvedIncident(string $status = 'resolved'): int
{
    $incident = IncidentFixtures::open(WorkforceFixtures::employee(WorkforceFixtures::site()));

    DB::table('incidents')->where('id', $incident)->update([
        'status' => $status,
        'resolved_at' => '2026-03-20 09:00:00+00',
        'resolved_by_user_id' => ManagementUsers::withRole(UserRole::ADMIN)->id,
        'resolution_note' => 'Revisado con la persona.',
        'updated_at' => '2026-03-20 09:00:00+00',
    ]);

    return $incident;
}

it('el motor rechaza reescribir la resolucion o el aviso de una incidencia', function (string $case): void {
    $resolved = transitionsResolvedIncident();
    $open = IncidentFixtures::open(WorkforceFixtures::employee(WorkforceFixtures::site()));
    $other = ManagementUsers::withRole(UserRole::RRHH)->id;

    [$id, $values] = match ($case) {
        'cambiar la nota de una resuelta' => [$resolved, ['resolution_note' => 'Otra version.']],
        'cambiar cuando se resolvio' => [$resolved, ['resolved_at' => '2026-03-21 09:00:00+00']],
        'cambiar quien la resolvio' => [$resolved, ['resolved_by_user_id' => $other]],
        'pasar de resuelta a descartada' => [$resolved, ['status' => 'dismissed']],
        'reabrir una resuelta' => [$resolved, ['status' => 'open', 'resolved_at' => null, 'resolved_by_user_id' => null, 'resolution_note' => null]],
        'escribir una nota sin cerrarla' => [$open, ['resolution_note' => 'Nota suelta.']],
        'reescribir el primer aviso' => [$resolved, ['notified_at' => '2026-03-22 04:00:00+00']],
        default => throw new LogicException($case),
    };

    if ($case === 'reescribir el primer aviso') {
        DB::table('incidents')->where('id', $resolved)->update(['notified_at' => '2026-03-19 04:00:00+00']);
    }

    transitionsExpectDenied($case, static fn (ConnectionInterface $connection): int => $connection->table('incidents')
        ->where('id', $id)
        ->update([...$values, 'updated_at' => '2026-03-23 09:00:00+00']));
})->with([
    'cambiar la nota de una resuelta',
    'cambiar cuando se resolvio',
    'cambiar quien la resolvio',
    'pasar de resuelta a descartada',
    'reabrir una resuelta',
    'escribir una nota sin cerrarla',
    'reescribir el primer aviso',
])->group('RS-07', 'RL-04', 'RN-13');

it('el motor rechaza borrar el primer aviso de una incidencia', function (): void {
    $incident = IncidentFixtures::open(WorkforceFixtures::employee(WorkforceFixtures::site()));

    DB::table('incidents')->where('id', $incident)->update(['notified_at' => '2026-03-19 04:00:00+00']);

    transitionsExpectDenied('notified_at a nulo', static fn (ConnectionInterface $connection): int => $connection->table('incidents')
        ->where('id', $incident)
        ->update(['notified_at' => null]));
})->group('RS-07', 'RL-04', 'RN-13');

it('el propietario tampoco cambia el tipo ni el contexto de una incidencia', function (string $column, string $value): void {
    $incident = IncidentFixtures::open(WorkforceFixtures::employee(WorkforceFixtures::site()));

    transitionsExpectDenied(
        'propietario cambia '.$column,
        static fn (ConnectionInterface $owner): int => $owner->table('incidents')->where('id', $incident)->update([$column => $value]),
        SchemaOwner::connection(),
    );
})->with([
    'type' => ['type', 'short_shift'],
    'context' => ['context', '{}'],
    'detected_at' => ['detected_at', '2020-01-01 00:00:00+00'],
])->group('RS-07', 'RL-04', 'RN-13');

it('resolver, descartar y avisar pasan con el rol de la aplicacion', function (string $outcome): void {
    $incident = IncidentFixtures::open(WorkforceFixtures::employee(WorkforceFixtures::site()));

    // Primer aviso de una abierta.
    DB::table('incidents')->where('id', $incident)->whereNull('notified_at')->update([
        'notified_at' => '2026-03-19 04:00:00+00',
        'updated_at' => '2026-03-19 04:00:00+00',
    ]);

    // La resolucion, con el `WHERE status = 'open'` de DatabaseIncidentLedger.
    $affected = DB::table('incidents')->where('id', $incident)->where('status', 'open')->update([
        'status' => $outcome,
        'resolved_at' => '2026-03-20 09:00:00+00',
        'resolved_by_user_id' => ManagementUsers::withRole(UserRole::ADMIN)->id,
        'resolution_note' => 'Revisado.',
        'updated_at' => '2026-03-20 09:00:00+00',
    ]);

    // Y el primer aviso de una ya cerrada que nadie habia avisado.
    $closed = transitionsResolvedIncident();
    DB::table('incidents')->where('id', $closed)->whereNull('notified_at')->update(['notified_at' => '2026-03-21 04:00:00+00']);

    expect($affected)->toBe(1)
        ->and(IncidentFixtures::stored($incident)->status)->toBe($outcome)
        ->and(DB::table('incidents')->where('id', $closed)->value('notified_at'))->not->toBeNull();
})->with(['resolved', 'dismissed'])->group('RS-07', 'RL-04', 'RN-13');

// --- Lo que nace: el INSERT tambien pasa por el motor ---------------------------

it('el motor rechaza insertar un tramo ya sustituido, anulado o encadenado', function (string $case): void {
    // Sin `shift_entries_guard_insert`, el `UPDATE` vigilado se esquivaba
    // insertando directamente el estado final: un tramo que nunca fue vigente
    // y que la exportacion no enseña (revision de seguridad del bloque 7).
    $target = transitionsEntry('closed');
    $site = WorkforceFixtures::site();

    $values = match ($case) {
        'superseded' => ['status' => 'superseded'],
        'voided' => ['status' => 'voided'],
        'con superseded_by_id' => ['status' => 'closed', 'superseded_by_id' => $target['id']],
        default => throw new LogicException($case),
    };

    transitionsExpectDenied('insertar '.$case, static fn (ConnectionInterface $connection): bool => $connection->table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $target['employee'],
        'site_id' => $site,
        'work_date' => '2026-03-14',
        // Lejos del tramo de la fixture: lo que se prueba es la guarda, no RN-02.
        'clocked_in_at' => '2026-03-14 15:00:00+00',
        'clocked_out_at' => '2026-03-14 15:30:00+00',
        'duration_minutes' => 30,
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 2,
        'created_at' => '2026-03-14 06:00:00+00',
        'updated_at' => '2026-03-14 06:00:00+00',
        ...$values,
    ]));

    expect(DB::table('shift_entries')->where('employee_id', $target['employee'])->count())->toBe(1);
})->with(['superseded', 'voided', 'con superseded_by_id'])->group('RS-07', 'RL-04', 'RN-13');

it('el motor rechaza insertar una incidencia ya resuelta o avisada', function (string $case): void {
    $employee = AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee(WorkforceFixtures::site()));
    $user = ManagementUsers::withRole(UserRole::ADMIN)->id;

    $values = match ($case) {
        'resuelta' => ['status' => 'resolved', 'resolved_at' => '2026-03-15 09:00:00+00', 'resolved_by_user_id' => $user, 'resolution_note' => 'Revisado.'],
        'descartada' => ['status' => 'dismissed', 'resolved_at' => '2026-03-15 09:00:00+00', 'resolved_by_user_id' => $user],
        'abierta con nota' => ['resolution_note' => 'Nota previa.'],
        'abierta y ya avisada' => ['notified_at' => '2026-03-15 04:00:00+00'],
        default => throw new LogicException($case),
    };

    transitionsExpectDenied('insertar una incidencia '.$case, static fn (ConnectionInterface $connection): bool => $connection->table('incidents')->insert([
        'employee_id' => $employee,
        'work_date' => '2026-03-14',
        'type' => 'insufficient_rest',
        'severity' => 'high',
        'status' => 'open',
        'detected_at' => '2026-03-15 03:30:00+00',
        'context' => '{}',
        'created_at' => '2026-03-15 03:30:00+00',
        'updated_at' => '2026-03-15 03:30:00+00',
        ...$values,
    ]));

    expect(DB::table('incidents')->where('employee_id', $employee)->count())->toBe(0);
})->with([
    // Una abierta con `resolved_at` o `resolved_by_user_id` ya la rechaza
    // `incidents_chk_resolution_is_complete` (`23514`); la nota y el aviso no.
    'resuelta',
    'descartada',
    'abierta con nota',
    'abierta y ya avisada',
])->group('RS-07', 'RL-04', 'RN-13');

it('el upsert de la jornada no dispara la guarda del INSERT al retirar una version', function (): void {
    // `AFTER INSERT` y no `BEFORE`: `ON CONFLICT DO UPDATE` sobre una fila que
    // ya existe propone `superseded` y no inserta nada. Con un `BEFORE INSERT`
    // toda correccion fallaria.
    $old = transitionsEntry('closed');
    $row = (array) DB::table('shift_entries')->where('id', $old['id'])->first();
    unset($row['id']);

    DB::transaction(static function () use ($old, $row): void {
        DB::table('shift_entries')->upsert([[...$row, 'status' => 'superseded']], ['uuid'], ['status', 'updated_at']);

        $replacement = transitionsEntry('closed', 2, '2026-03-14', $old['employee']);

        transitionsUpdate($old['id'], ['superseded_by_id' => $replacement['id']])(DB::connection());
    });

    expect(DB::table('shift_entries')->where('id', $old['id'])->value('status'))->toBe('superseded');
})->group('RS-07', 'RL-04', 'RN-13');

// --- Una sustituta, una sola version que la reclame ------------------------------

it('dos versiones no pueden apuntar a la misma sustituta', function (): void {
    $old = transitionsEntry('superseded');
    $replacement = DB::table('shift_entries')->where('id', $old['id'])->value('superseded_by_id');

    expect($replacement)->toBeInt();
    $twin = transitionsEntry('closed', 1, '2026-03-14', $old['employee'], hour: 12);

    // El *trigger* la deja pasar —mismo empleado, misma jornada, version
    // siguiente— y la para el indice unico.
    try {
        DB::transaction(transitionsUpdate($twin['id'], [
            'status' => 'superseded',
            'superseded_by_id' => $replacement,
        ]));
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe('23505')
            ->and($exception->getMessage())->toContain('shift_entries_superseded_by_id_unique');

        return;
    }

    Assert::fail('PostgreSQL ha admitido dos versiones que apuntan a la misma sustituta.');
})->group('RS-07', 'RL-04', 'RN-13');

it('dos encadenados simultaneos hacia la misma sustituta no pasan los dos', function (): void {
    // Lo que el `EXISTS` del *trigger* no podia ver: la fila sin confirmar de
    // otra transaccion. Con el indice unico, la segunda espera a la primera.
    $old = transitionsEntry('closed');
    $replacement = transitionsEntry('closed', 2, '2026-03-14', $old['employee']);
    $twin = transitionsEntry('closed', 1, '2026-03-14', $old['employee'], hour: 12);

    config(['database.connections.transitions_rival' => config()->array('database.connections.'.config()->string('database.default'))]);
    $rival = DB::connection('transitions_rival');

    try {
        $rival->beginTransaction();
        $rival->table('shift_entries')->where('id', $old['id'])->update(['status' => 'superseded', 'superseded_by_id' => $replacement['id']]);

        DB::transaction(static function () use ($twin, $replacement): void {
            DB::statement("SET LOCAL lock_timeout = '300ms'");

            transitionsUpdate($twin['id'], ['status' => 'superseded', 'superseded_by_id' => $replacement['id']])(DB::connection());
        });

        Assert::fail('El segundo encadenado no ha esperado al primero.');
    } catch (PDOException $exception) {
        // `55P03`: espera la decision de la otra transaccion sobre la misma
        // clave del indice, en vez de dar por libre la sustituta.
        expect($exception->getCode())->toBe('55P03');
    } finally {
        $rival->rollBack();
        DB::purge('transitions_rival');
    }
})->group('RS-07', 'RL-04', 'RN-13');

// --- Borrar una cuenta que figura en una incidencia ------------------------------

it('borrar una cuenta con incidencias falla por la clave ajena y no por el trigger', function (string $column): void {
    // Las dos claves hacia `users` eran `SET NULL`: la accion referencial es un
    // `UPDATE` que la guarda rechazaba con un `42501` que no hablaba de la
    // cuenta (`2026_10_14_100100`; arquitecto H1). Ahora es `RESTRICT`.
    $user = ManagementUsers::withRole(UserRole::ADMIN)->id;
    $incident = IncidentFixtures::open(
        WorkforceFixtures::employee(WorkforceFixtures::site()),
        assignedToUserId: $column === 'assigned_to_user_id' ? $user : null,
    );

    if ($column === 'resolved_by_user_id') {
        DB::table('incidents')->where('id', $incident)->update([
            'status' => 'resolved',
            'resolved_at' => '2026-03-20 09:00:00+00',
            'resolved_by_user_id' => $user,
            'resolution_note' => 'Revisado.',
        ]);
    }

    $owner = SchemaOwner::connection();

    try {
        $owner->transaction(static fn (): int => $owner->table('users')->where('id', $user)->delete());
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe('23503')
            ->and($exception->getMessage())->toContain('incidents_'.$column.'_foreign')
            ->and(DB::table('incidents')->where('id', $incident)->value($column))->toBe($user);

        return;
    }

    Assert::fail('Se ha borrado una cuenta que figura en una incidencia.');
})->with(['assigned_to_user_id', 'resolved_by_user_id'])->group('RL-04', 'RN-13');

// --- La aplicacion no puede quitar las guardas ---------------------------------

it('el rol de la aplicacion no puede desactivar, soltar ni reescribir las guardas', function (string $statement): void {
    try {
        DB::transaction(static fn (): bool => DB::statement($statement));
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe('42501', $exception->getMessage());

        return;
    }

    Assert::fail('El rol de la aplicacion ha podido ejecutar: '.$statement);
})->with([
    'DISABLE TRIGGER' => ['ALTER TABLE shift_entries DISABLE TRIGGER shift_entries_guard_transition'],
    'DISABLE TRIGGER ALL' => ['ALTER TABLE incidents DISABLE TRIGGER ALL'],
    'DROP TRIGGER' => ['DROP TRIGGER shift_entries_guard_transition ON shift_entries'],
    'DROP TRIGGER del INSERT' => ['DROP TRIGGER incidents_guard_insert ON incidents'],
    'session_replication_role' => ['SET session_replication_role = replica'],
    'reescribir la funcion' => ['CREATE OR REPLACE FUNCTION public.shift_entries_guard_transition() '
        .'RETURNS trigger LANGUAGE plpgsql AS $f$ BEGIN RETURN NEW; END; $f$'],
])->group('RS-07', 'RL-04');

it('las tablas guardadas y sus funciones son del rol de migracion', function (): void {
    $migrator = Config::string('database.roles.migration');

    /** @var list<object{name: string, owner: string}> $tables */
    $tables = DB::select(<<<'SQL'
        SELECT c.relname AS name, pg_get_userbyid(c.relowner) AS owner
          FROM pg_class c
         WHERE c.oid IN ('public.shift_entries'::regclass, 'public.incidents'::regclass)
         ORDER BY c.relname
        SQL);

    /** @var list<object{name: string, owner: string}> $functions */
    $functions = DB::select(<<<'SQL'
        SELECT p.proname AS name, pg_get_userbyid(p.proowner) AS owner
          FROM pg_proc p
         WHERE p.pronamespace = 'public'::regnamespace
           AND p.proname IN ('shift_entries_guard_transition', 'shift_entries_guard_insert',
                             'shift_entries_assert_superseded_is_linked', 'incidents_guard_transition',
                             'incidents_guard_insert')
         ORDER BY p.proname
        SQL);

    expect(array_map(static fn (object $row): string => $row->owner, $tables))->toBe([$migrator, $migrator])
        ->and($functions)->toHaveCount(5)
        ->and(array_values(array_unique(array_map(static fn (object $row): string => $row->owner, $functions))))->toBe([$migrator])
        ->and($migrator)->not->toBe(Config::string('database.roles.application'));
})->group('RS-07', 'RL-04');

// --- El upsert de la jornada sobre una fila que no cuadra ------------------------

it('guardar la jornada no reescribe la duracion de un tramo cerrado que no cuadra con sus marcas', function (): void {
    // El caso que motivo el `CASE` de `EloquentWorkDayRepository::persistEntries`:
    // una fila antigua con una duracion que no sale de sus marcas (importada, o
    // manipulada y pendiente de remediar). La jornada se guarda entera, el
    // agregado recalcula 30 minutos, y sin el `CASE` el *trigger* rechazaria
    // cada fichaje y cada correccion de ese dia.
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site());
    $entry = transitionsEntry('closed', employeeId: AttendanceFixtures::employeeIdOf($employee));

    SchemaOwner::bypassingTransitionGuards(static fn (ConnectionInterface $owner): int => $owner->table('shift_entries')
        ->where('id', $entry['id'])
        ->update(['duration_minutes' => 999, 'clocked_out_at' => '2026-03-14 07:30:00.123456+00']));

    $repository = app(WorkDayRepository::class);
    $workDay = $repository->findWorkDayFor($employee, WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')));

    if (! $workDay instanceof WorkDay) {
        Assert::fail('La jornada no se ha cargado.');
    }

    DB::transaction(static fn () => $repository->save($workDay));

    /** @var object{duration_minutes: int, status: string, same_exit: bool} $row */
    $row = DB::selectOne(
        "SELECT duration_minutes, status, clocked_out_at = '2026-03-14 07:30:00.123456+00'::timestamptz AS same_exit "
        .'FROM shift_entries WHERE id = ?',
        [$entry['id']],
    );

    expect($row->duration_minutes)->toBe(999)
        ->and($row->status)->toBe('closed')
        // Y la salida, con sus microsegundos: el agregado escribe la que leyo,
        // asi que el `upsert` sin `CASE` sobre ella no la mueve.
        ->and($row->same_exit)->toBeTrue();
})->group('RS-07', 'RL-04', 'RN-06');
