<?php

declare(strict_types=1);

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
 * Un tramo en el estado pedido, escrito por la aplicacion (INSERT).
 *
 * @return array{id: int, uuid: string, employee: int}
 */
function transitionsEntry(string $status, int $version = 1, string $workDate = '2026-03-14', ?int $employeeId = null): array
{
    $site = WorkforceFixtures::site();
    $employeeId ??= AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));
    $uuid = Str::uuid7()->toString();
    $open = $status === 'open';
    // Cada version en su hueco: la restriccion de exclusion (RN-02) no admite
    // dos tramos vigentes que se pisen.
    $hour = 6 + $version;

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
])->group('RS-07', 'RL-04', 'RN-13');

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
    $another = transitionsEntry('voided', 2, '2026-03-14', $old['employee']);

    transitionsExpectDenied('cambiar superseded_by_id', transitionsUpdate($old['id'], ['superseded_by_id' => $another['id']]));
    transitionsExpectDenied('quitar superseded_by_id', transitionsUpdate($old['id'], ['superseded_by_id' => null]));

    // Ni otra version puede reclamar la misma sustituta.
    $twin = transitionsEntry('superseded', 1, '2026-03-14', $old['employee']);

    transitionsExpectDenied('dos versiones apuntan a la misma', transitionsUpdate($twin['id'], ['superseded_by_id' => $replacement['id']]));
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
])->group('RS-07', 'RL-04', 'RN-13');

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
