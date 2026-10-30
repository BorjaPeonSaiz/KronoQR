<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LOS PERIODOS DE ALTA EN LA BASE DE DATOS** (RN-27, RN-14, RN-13; ADR-062
 * §1 y §2, que enmienda ADR-046).
 *
 * Contra PostgreSQL de verdad, con SQL directo y la credencial de la aplicacion:
 *
 * - `employees_guard_employment_transition` admite cada transicion de la tabla
 *   de ADR-062 §2 y responde `42501` a las demas: la unica salida de
 *   `terminated` es la readmision, con fecha de alta posterior al cese.
 * - `employees_current_period_matches` falla el `COMMIT` con la ficha y su
 *   periodo en curso descuadrados.
 * - El `EXCLUDE` de los periodos, `one_open_employment_period`, la guarda de
 *   `employment_periods` y la falta de `DELETE` y de `UPDATE` fuera de las dos
 *   columnas.
 * - La migracion: su relleno con datos de la 2.2.x y con una baja de la 2.1.0
 *   con cese futuro, y su `down()`, que se niega con historia que perder.
 *
 * `CommittedDatabase`: la comprobacion diferida solo corre al confirmar, y la
 * prueba del `down()` suelta tablas que otra sesion tiene que poder ver.
 */

uses(CommittedDatabase::class);

const EMPLOYMENT_PERIODS_MIGRATION = '2026_10_30_100000_create_employment_periods';

/**
 * Ejecuta `$write` en su propia transaccion, hasta el `COMMIT`, y exige que
 * PostgreSQL la corte con `$code`.
 */
function periodosEsperaRechazo(string $what, Closure $write, string $code): void
{
    try {
        DB::transaction(static function () use ($write): void {
            $write(DB::connection());
        });
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe($code, $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail('PostgreSQL ha admitido «'.$what.'».');
}

/** Ejecuta `$write` en su transaccion y exige que confirme. */
function periodosEsperaExito(Closure $write): void
{
    DB::transaction(static function () use ($write): void {
        $write(DB::connection());
    });
}

/** Una persona de baja desde el 30 de junio de 2026, con su periodo cerrado. */
function periodosPersonaDeBaja(): string
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    WorkforceFixtures::terminate($uuid);

    return $uuid;
}

function periodosIdDe(string $uuid): int
{
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');
    \assert(is_numeric($id));

    return (int) $id;
}

it('corre con el rol de la aplicacion, que es al que se le niega', function (): void {
    /** @var object{name: string} $row */
    $row = DB::selectOne('SELECT current_user AS name');

    expect($row->name)->toBe(config()->string('database.roles.application', 'fichaje_app'));
})->group('RN-27');

it('admite suspender, programar la baja, anularla y hacerla efectiva, cada una con su periodo', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    $id = periodosIdDe($uuid);

    periodosEsperaExito(static fn (ConnectionInterface $db): int => $db->update("UPDATE employees SET status = 'suspended' WHERE id = ?", [$id]));
    periodosEsperaExito(static fn (ConnectionInterface $db): int => $db->update("UPDATE employees SET status = 'active' WHERE id = ?", [$id]));

    periodosEsperaExito(static function (ConnectionInterface $db) use ($id): void {
        $db->update("UPDATE employees SET terminated_at = '2026-11-30' WHERE id = ?", [$id]);
        $db->update("UPDATE employment_periods SET terminated_on = '2026-11-30' WHERE employee_id = ?", [$id]);
    });
    periodosEsperaExito(static function (ConnectionInterface $db) use ($id): void {
        $db->update('UPDATE employees SET terminated_at = NULL WHERE id = ?', [$id]);
        $db->update('UPDATE employment_periods SET terminated_on = NULL WHERE employee_id = ?', [$id]);
    });
    periodosEsperaExito(static function (ConnectionInterface $db) use ($id): void {
        $db->update("UPDATE employees SET status = 'terminated', terminated_at = '2026-10-31' WHERE id = ?", [$id]);
        $db->update("UPDATE employment_periods SET terminated_on = '2026-10-31', offboarded_at = now() WHERE employee_id = ?", [$id]);
    });

    expect(DB::table('employees')->where('id', $id)->value('status'))->toBe('terminated');
})->group('RN-27', 'RN-14', 'RN-25');

it('admite corregir el cese de una baja y la readmision con fecha posterior al cese', function (): void {
    $id = periodosIdDe(periodosPersonaDeBaja());

    periodosEsperaExito(static function (ConnectionInterface $db) use ($id): void {
        $db->update("UPDATE employees SET terminated_at = '2026-07-03' WHERE id = ?", [$id]);
        $db->update("UPDATE employment_periods SET terminated_on = '2026-07-03' WHERE employee_id = ?", [$id]);
    });

    periodosEsperaExito(static function (ConnectionInterface $db) use ($id): void {
        $db->update("UPDATE employees SET status = 'active', hired_at = '2026-07-04', terminated_at = NULL WHERE id = ?", [$id]);
        $db->insert("INSERT INTO employment_periods (uuid, employee_id, hired_on) VALUES (gen_random_uuid(), ?, '2026-07-04')", [$id]);
    });

    expect(DB::table('employment_periods')->where('employee_id', $id)->count())->toBe(2);
})->group('RN-27');

it('responde 42501 a cualquier otra transicion de la ficha', function (string $what, string $sql): void {
    $id = periodosIdDe(periodosPersonaDeBaja());

    periodosEsperaRechazo($what, static fn (ConnectionInterface $db): int => $db->update($sql, [$id]), '42501');
})->with([
    'de baja a suspendida' => ['de baja a suspendida', "UPDATE employees SET status = 'suspended' WHERE id = ?"],
    'reactivar sin fecha de alta nueva' => ['reactivar sin fecha de alta nueva', "UPDATE employees SET status = 'active', terminated_at = NULL WHERE id = ?"],
    'readmitir el dia del cese' => ['readmitir el dia del cese', "UPDATE employees SET status = 'active', hired_at = '2026-06-30', terminated_at = NULL WHERE id = ?"],
    'readmitir conservando el cese' => ['readmitir conservando el cese', "UPDATE employees SET status = 'active', hired_at = '2026-07-04' WHERE id = ?"],
    'cambiar el alta de una baja' => ['cambiar el alta de una baja', "UPDATE employees SET hired_at = '2025-01-01' WHERE id = ?"],
    'quitar el cese de una baja' => ['quitar el cese de una baja', 'UPDATE employees SET terminated_at = NULL WHERE id = ?'],
])->group('RN-27', 'RN-14');

it('responde 42501 a cambiar el alta de una persona en alta sin readmitirla', function (): void {
    $id = periodosIdDe(WorkforceFixtures::employee(WorkforceFixtures::site()));

    periodosEsperaRechazo('mover el alta', static fn (ConnectionInterface $db): int => $db->update("UPDATE employees SET hired_at = '2025-06-01' WHERE id = ?", [$id]), '42501');
    periodosEsperaRechazo('suspender y programar a la vez', static fn (ConnectionInterface $db): int => $db->update("UPDATE employees SET status = 'suspended', terminated_at = '2026-11-30' WHERE id = ?", [$id]), '42501');
})->group('RN-27');

it('falla el COMMIT si la ficha y su periodo en curso no coinciden', function (): void {
    $id = periodosIdDe(WorkforceFixtures::employee(WorkforceFixtures::site()));

    periodosEsperaRechazo('programar la baja sin el periodo', static fn (ConnectionInterface $db): int => $db->update("UPDATE employees SET terminated_at = '2026-11-30' WHERE id = ?", [$id]), '23514');
    periodosEsperaRechazo('cerrar el periodo sin la ficha', static fn (ConnectionInterface $db): int => $db->update("UPDATE employment_periods SET terminated_on = '2026-11-30', offboarded_at = now() WHERE employee_id = ?", [$id]), '23514');
})->group('RN-27');

it('no deja dos periodos solapados ni dos sin baja efectiva', function (): void {
    $id = periodosIdDe(periodosPersonaDeBaja());

    periodosEsperaRechazo('un periodo que pisa al anterior', static fn (ConnectionInterface $db): bool => $db->insert("INSERT INTO employment_periods (uuid, employee_id, hired_on, terminated_on, offboarded_at) VALUES (gen_random_uuid(), ?, '2026-06-30', '2026-06-30', now())", [$id]), '23P01');

    $abierta = periodosIdDe(WorkforceFixtures::employee(WorkforceFixtures::site()));
    // Con la baja programada el periodo en curso tiene cese y no solapa con
    // otro posterior, pero sigue sin baja efectiva: no caben dos asi.
    periodosEsperaRechazo('un segundo periodo abierto', static function (ConnectionInterface $db) use ($abierta): bool {
        $db->update("UPDATE employees SET terminated_at = '2026-11-30' WHERE id = ?", [$abierta]);
        $db->update("UPDATE employment_periods SET terminated_on = '2026-11-30' WHERE employee_id = ?", [$abierta]);

        return $db->insert("INSERT INTO employment_periods (uuid, employee_id, hired_on) VALUES (gen_random_uuid(), ?, '2027-01-01')", [$abierta]);
    }, '23505');
})->group('RN-27');

it('no deja reescribir la baja efectiva de un periodo, quitarle el cese ni cambiar su alta', function (): void {
    $id = periodosIdDe(periodosPersonaDeBaja());

    periodosEsperaRechazo('reescribir offboarded_at', static fn (ConnectionInterface $db): int => $db->update("UPDATE employment_periods SET offboarded_at = now() - interval '1 day' WHERE employee_id = ?", [$id]), '42501');
    periodosEsperaRechazo('quitar el cese', static fn (ConnectionInterface $db): int => $db->update('UPDATE employment_periods SET terminated_on = NULL WHERE employee_id = ?', [$id]), '42501');
    periodosEsperaRechazo('cambiar el alta del periodo', static fn (ConnectionInterface $db): int => $db->update("UPDATE employment_periods SET hired_on = '2025-01-01' WHERE employee_id = ?", [$id]), '42501');
})->group('RN-27', 'RN-13');

it('no da a la aplicacion DELETE sobre los periodos ni UPDATE ni DELETE sobre las correcciones', function (): void {
    $role = config()->string('database.roles.application', 'fichaje_app');

    /** @var object{periods_delete: bool, corrections_update: bool, corrections_delete: bool, periods_update_hired: bool, periods_update_cese: bool} $row */
    $row = DB::selectOne(<<<'SQL'
        SELECT has_table_privilege(?, 'employment_periods', 'DELETE') AS periods_delete,
               has_table_privilege(?, 'employment_period_corrections', 'UPDATE') AS corrections_update,
               has_table_privilege(?, 'employment_period_corrections', 'DELETE') AS corrections_delete,
               has_column_privilege(?, 'employment_periods', 'hired_on', 'UPDATE') AS periods_update_hired,
               has_column_privilege(?, 'employment_periods', 'terminated_on', 'UPDATE') AS periods_update_cese
        SQL, [$role, $role, $role, $role, $role]);

    expect($row->periods_delete)->toBeFalse()
        ->and($row->corrections_update)->toBeFalse()
        ->and($row->corrections_delete)->toBeFalse()
        ->and($row->periods_update_hired)->toBeFalse()
        ->and($row->periods_update_cese)->toBeTrue();
})->group('RN-27', 'RN-13');

/**
 * Deshace la migracion de los periodos, ejecuta `$between` con el propietario
 * y la vuelve a aplicar. Devuelve la salida del `rollback`.
 *
 * @return array{0: int, 1: string}
 */
function periodosSinLaMigracion(Closure $between): array
{
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    /** @var list<string> $applied */
    $applied = $migrator->table('migrations')->orderByDesc('id')->pluck('migration')->all();
    $steps = array_search(EMPLOYMENT_PERIODS_MIGRATION, $applied, true);
    \assert(is_int($steps));

    try {
        $back = Commands::run('migrate:rollback --database='.$name.' --step='.($steps + 1));
        $between($migrator);
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput);

    return $back;
}

it('rellena un periodo por ficha, tambien la baja de la 2.1.0 con cese futuro, y su down() vuelve atras', function (): void {
    $site = WorkforceFixtures::site();
    $enAlta = WorkforceFixtures::employee($site);
    $deBaja = periodosPersonaDeBaja();

    [$back, $backOutput] = periodosSinLaMigracion(static function (Connection $owner) use ($site): void {
        expect($owner->getSchemaBuilder()->hasTable('employment_periods'))->toBeFalse();

        // Una baja de la 2.1.0: efectiva con un cese posterior a hoy.
        $owner->table('employees')->insert([
            'uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99',
            'site_id' => $site,
            'first_name' => 'Baja',
            'last_name' => 'Antigua',
            'employee_code' => 'EOLDLEAVE1',
            'status' => 'terminated',
            'hired_at' => '2025-03-01',
            'terminated_at' => '2099-12-31',
            'locale' => 'es',
            'updated_at' => '2026-09-01 10:00:00+00',
        ]);
    });

    expect($back)->toBe(0, $backOutput);

    /** @var list<object{uuid: string, hired_on: string, terminated_on: string|null, offboarded: bool}> $periodos */
    $periodos = DB::select(<<<'SQL'
        SELECT e.uuid::text AS uuid, p.hired_on::text AS hired_on, p.terminated_on::text AS terminated_on,
               (p.offboarded_at IS NOT NULL) AS offboarded
          FROM employment_periods p JOIN employees e ON e.id = p.employee_id
         ORDER BY e.id
        SQL);

    expect(array_map(static fn (object $p): array => (array) $p, $periodos))->toBe([
        ['uuid' => $enAlta, 'hired_on' => '2026-01-01', 'terminated_on' => null, 'offboarded' => false],
        ['uuid' => $deBaja, 'hired_on' => '2026-01-01', 'terminated_on' => '2026-06-30', 'offboarded' => true],
        ['uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99', 'hired_on' => '2025-03-01', 'terminated_on' => '2099-12-31', 'offboarded' => true],
    ])
        // Sin asiento `employee.offboarded`, el instante de la baja es el de la
        // ultima modificacion de la ficha.
        ->and(DB::table('employment_periods')->where('hired_on', '2025-03-01')->value('offboarded_at'))->toStartWith('2026-09-01 10:00:00');
})->group('RN-27', 'RF-PD-10', 'RNF-D-04');

it('su down() se niega con una readmision hecha: perderia historia', function (): void {
    $id = periodosIdDe(periodosPersonaDeBaja());

    periodosEsperaExito(static function (ConnectionInterface $db) use ($id): void {
        $db->update("UPDATE employees SET status = 'active', hired_at = '2026-08-01', terminated_at = NULL WHERE id = ?", [$id]);
        $db->insert("INSERT INTO employment_periods (uuid, employee_id, hired_on) VALUES (gen_random_uuid(), ?, '2026-08-01')", [$id]);
    });

    $refused = null;

    try {
        periodosSinLaMigracion(static function (): void {});
    } catch (RuntimeException $exception) {
        $refused = $exception->getMessage();
    }

    expect($refused)->toContain('hay readmisiones o correcciones de fecha de cese')
        ->and(DB::table('employment_periods')->where('employee_id', $id)->count())->toBe(2);
})->group('RN-27', 'RF-PD-10', 'RN-13');
