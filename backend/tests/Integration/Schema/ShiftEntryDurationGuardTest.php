<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\ElapsedMinutes;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA DURACION QUE SE ESCRIBE EN UN TRAMO ES LA DE SUS MARCAS**, tambien en el
 * motor (RN-06, RN-09, regla dura 7, ADR-007, ADR-057 §2; tarea 19.6 de la
 * 2.3.0, migracion `2026_10_25_100000`).
 *
 * `daily_totals` se recalcula desde las marcas y la exportacion legal suma
 * `shift_entries.duration_minutes`. Si una fila naciera con una duracion que no
 * cuadra, el registro se contradiria a si mismo. Aqui se comprueba que:
 *
 *   1. el motor rechaza con `23514` el tramo que nace o se cierra con otra
 *      duracion, o con duracion sin salida;
 *   2. la cuenta del motor es la de `ElapsedMinutes::between()`, con las marcas
 *      que separan truncar cada marca a su segundo de restar con microsegundos;
 *   3. un tramo antiguo descuadrado —el que la conciliacion de ADR-057 §4 tiene
 *      que encontrar— no se reescribe ni bloquea la jornada: el fichaje del dia
 *      sigue guardandose.
 *
 * `CommittedDatabase`: la fila antigua se siembra con el propietario, que es
 * otra sesion, y la aplicacion tiene que verla.
 */

uses(CommittedDatabase::class);

const SHIFT_ENTRY_DURATION_GUARD_DAY = '2026-03-14';

/**
 * Inserta un tramo con la conexion dada y devuelve su `id`.
 *
 * @param  array<string, mixed>  $values
 */
function shiftEntryDurationGuardInsert(array $values, ?ConnectionInterface $connection = null): int
{
    $connection ??= DB::connection();
    $site = WorkforceFixtures::site();
    $employeeId = AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));

    return (int) $connection->table('shift_entries')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $site,
        'work_date' => SHIFT_ENTRY_DURATION_GUARD_DAY,
        'clocked_in_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 08:00:00+00',
        'clocked_out_at' => null,
        'duration_minutes' => null,
        'status' => 'open',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => null,
        'version' => 1,
        'created_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 08:00:00+00',
        'updated_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 08:00:00+00',
        ...$values,
    ]);
}

/**
 * Ejecuta `$write` en su transaccion y exige el `23514` del *trigger*.
 *
 * @param  Closure(): mixed  $write
 */
function shiftEntryDurationGuardExpectRejected(string $what, Closure $write): void
{
    try {
        DB::transaction($write);
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe('23514', $what.': '.$exception->getMessage())
            ->and($exception->getMessage())->toContain('la duracion de un tramo es la de sus marcas');

        return;
    }

    Assert::fail('PostgreSQL ha admitido «'.$what.'».');
}

/**
 * Un tramo cerrado entre esas dos marcas, con la duracion dada.
 *
 * @return Closure(): int
 */
function shiftEntryDurationGuardClosed(string $in, string $out, ?int $minutes): Closure
{
    return static fn (): int => shiftEntryDurationGuardInsert([
        'clocked_in_at' => $in,
        'clocked_out_at' => $out,
        'duration_minutes' => $minutes,
        'status' => 'closed',
        'clock_out_source' => 'qr_kiosk',
        'closed_by' => 'clock_out',
    ]);
}

it('rechaza el tramo que nace con una duracion que no es la de sus marcas', function (?int $minutes): void {
    shiftEntryDurationGuardExpectRejected(
        'tramo cerrado de 60 minutos con '.var_export($minutes, true),
        shiftEntryDurationGuardClosed(SHIFT_ENTRY_DURATION_GUARD_DAY.' 08:00:00+00', SHIFT_ENTRY_DURATION_GUARD_DAY.' 09:00:00+00', $minutes),
    );

    expect(DB::table('shift_entries')->count())->toBe(0);
})->with([
    'un minuto de mas' => [61],
    'un minuto de menos' => [59],
    'cero' => [0],
    'sin duracion' => [null],
])->group('RN-06', 'RN-09', 'RL-04');

it('rechaza el tramo abierto que nace con duracion', function (): void {
    shiftEntryDurationGuardExpectRejected(
        'tramo abierto con duracion',
        static fn (): int => shiftEntryDurationGuardInsert(['duration_minutes' => 30]),
    );
})->group('RN-06', 'RN-09', 'RL-04');

it('rechaza cerrar un tramo con una duracion que no es la de sus marcas', function (): void {
    $id = shiftEntryDurationGuardInsert([]);

    shiftEntryDurationGuardExpectRejected(
        'cierre de 8 h con 500 minutos',
        static fn (): int => DB::table('shift_entries')->where('id', $id)->update([
            'status' => 'closed',
            'clocked_out_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 16:00:00+00',
            'duration_minutes' => 500,
            'clock_out_source' => 'qr_kiosk',
            'closed_by' => 'clock_out',
        ]),
    );

    expect(DB::table('shift_entries')->where('id', $id)->value('status'))->toBe('open');

    // El mismo cierre con su duracion pasa.
    DB::transaction(static fn (): int => DB::table('shift_entries')->where('id', $id)->update([
        'status' => 'closed',
        'clocked_out_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 16:00:00+00',
        'duration_minutes' => 480,
        'clock_out_source' => 'qr_kiosk',
        'closed_by' => 'clock_out',
    ]));

    expect(DB::table('shift_entries')->where('id', $id)->value('duration_minutes'))->toBe(480);
})->group('RN-06', 'RN-09', 'RL-04');

it('cuenta los minutos igual que ElapsedMinutes', function (string $in, string $out): void {
    $expected = ElapsedMinutes::between(new DateTimeImmutable($in), new DateTimeImmutable($out))->minutes;

    // La de la regla unica entra…
    $id = DB::transaction(shiftEntryDurationGuardClosed($in, $out, $expected));

    expect(DB::table('shift_entries')->where('id', $id)->value('duration_minutes'))->toBe($expected);

    // …y la del vecino no: el motor no admite dos respuestas.
    DB::table('shift_entries')->where('id', $id)->update(['status' => 'voided']);

    shiftEntryDurationGuardExpectRejected('vecino de abajo', shiftEntryDurationGuardClosed($in, $out, $expected - 1));
    shiftEntryDurationGuardExpectRejected('vecino de arriba', shiftEntryDurationGuardClosed($in, $out, $expected + 1));
})->with([
    // 59 min 59,2 s con microsegundos; 60 min con cada marca truncada a su
    // segundo, que es la regla (ElapsedMinutes).
    'fracciones que se cruzan' => ['2026-03-14 08:00:00.900000+00', '2026-03-14 09:00:00.100000+00'],
    // 60 min 59,999999 s: se trunca, no se redondea.
    'casi un minuto mas' => ['2026-03-14 08:00:00.000000+00', '2026-03-14 09:00:59.999999+00'],
    'un segundo de mas' => ['2026-03-14 08:00:00+00', '2026-03-14 08:01:01+00'],
    'turno de noche (RN-05)' => ['2026-03-14 22:00:00+00', '2026-03-15 06:00:00+00'],
    // Madrid pasa de 02:00 a 03:00 el 29-03-2026: en UTC no hay salto (RN-09).
    'cambio de hora' => ['2026-03-28 22:30:00+00', '2026-03-29 05:30:00+00'],
])->group('RN-06', 'RN-09', 'RN-05', 'RL-04');

it('un tramo antiguo descuadrado no se reescribe ni bloquea el fichaje del dia', function (): void {
    // Sembrado por fuera del agregado, como lo dejaria una manipulacion: 60
    // minutos de marcas y 75 guardados. Es la evidencia que la conciliacion de
    // ADR-057 §4 tiene que encontrar, y no se toca (regla dura 5).
    $legacyId = 0;
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $employeeId = AttendanceFixtures::employeeIdOf($employee);

    SchemaOwner::bypassingTransitionGuards(static function (ConnectionInterface $owner) use (&$legacyId, $site, $employeeId): void {
        $legacyId = (int) $owner->table('shift_entries')->insertGetId([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'site_id' => $site,
            'work_date' => SHIFT_ENTRY_DURATION_GUARD_DAY,
            'clocked_in_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 06:00:00+00',
            'clocked_out_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 07:00:00+00',
            'duration_minutes' => 75,
            'status' => 'closed',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'closed_by' => 'clock_out',
            'version' => 1,
            'created_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 06:00:00+00',
            'updated_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 07:00:00+00',
        ]);
    });

    // La jornada se guarda entera con el `upsert` del repositorio, que reescribe
    // el tramo antiguo: abrir uno nuevo ese mismo dia tiene que pasar.
    $repository = app(WorkDayRepository::class);

    DB::transaction(static function () use ($repository, $employee): void {
        $workDay = $repository->findWorkDayFor($employee, WorkDate::fromIsoDate(SHIFT_ENTRY_DURATION_GUARD_DAY, new DateTimeZone('UTC')));

        expect($workDay)->toBeInstanceOf(WorkDay::class);

        /** @var WorkDay $workDay */
        $workDay->clockIn(Str::uuid7()->toString(), new DateTimeImmutable(SHIFT_ENTRY_DURATION_GUARD_DAY.' 09:00:00+00:00'), ScanOrigin::QR_KIOSK);
        $repository->save($workDay);
    });

    expect(DB::table('shift_entries')->where('employee_id', $employeeId)->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('id', $legacyId)->value('duration_minutes'))->toBe(75);

    // Y anularlo desde el panel tambien pasa: el *trigger* solo mira el cierre.
    DB::transaction(static fn (): int => DB::table('shift_entries')->where('id', $legacyId)->update([
        'status' => 'voided',
        'updated_at' => SHIFT_ENTRY_DURATION_GUARD_DAY.' 10:00:00+00',
    ]));

    expect(DB::table('shift_entries')->where('id', $legacyId)->value('status'))->toBe('voided')
        ->and(DB::table('shift_entries')->where('id', $legacyId)->value('duration_minutes'))->toBe(75);
})->group('RN-06', 'RL-04', 'RN-13');
