<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;

/*
 * La ultima linea de defensa de RN-01, RN-02 y RN-03, probada **por SQL
 * directo** contra PostgreSQL real.
 *
 * Es la prueba de que esa ultima linea existe. Todo lo que se intenta aqui esta
 * ya prohibido en el dominio (tarea 1.1) y cubierto por pruebas unitarias
 * (tarea 1.2); lo que se comprueba aqui es lo OTRO: que si alguien escribe en
 * la tabla **sin pasar por el dominio** —una importacion, un script de
 * migracion, una correccion manual, una condicion de carrera bajo
 * concurrencia— la base de datos tambien lo rechaza. El doc 02 §3.2 lo dice sin
 * rodeos: «en un sistema con valor probatorio la integridad no puede depender
 * solo del codigo de aplicacion».
 *
 * Por eso ninguna prueba de este fichero usa un modelo, un repositorio ni un
 * caso de uso: se inserta con el constructor de consultas, que es lo mas
 * parecido a un `psql` que la suite puede ejecutar. **Y por eso lo que se
 * espera es un error de PostgreSQL con el nombre de la restriccion dentro, no
 * una excepcion de dominio.**
 *
 * Contra PostgreSQL de verdad y no SQLite: `EXCLUDE USING gist`, los indices
 * parciales y `tstzrange` no existen en SQLite, asi que una suite en memoria
 * daria estas garantias por buenas sin haberlas comprobado nunca.
 */

uses(RefreshDatabase::class);

/**
 * Instante fijo en lugar de «ahora»: las restricciones no dependen del reloj y
 * una prueba que si dependiera fallaria un dia al ano.
 */
const CLOCK_IN = '2026-03-02 06:00:00+00';

/**
 * Centro y dos empleados. Se construye dentro de cada prueba y no en un
 * `beforeEach` con estado en `$this`: Pest lo permite, pero el analisis
 * estatico no puede seguir esas propiedades y el §9.2 exige nivel 9 limpio.
 *
 * @return array{site: int, employee: int, other: int}
 */
function attendanceFixture(): array
{
    $site = DB::table('sites')->insertGetId([
        'name' => 'Centro de pruebas',
        'timezone' => 'Europe/Madrid',
        'settings' => '{}',
        'created_at' => CLOCK_IN,
    ]);

    return [
        'site' => $site,
        'employee' => employeeForTest($site, 'uno'),
        'other' => employeeForTest($site, 'dos'),
    ];
}

/**
 * Un empleado minimo. No hace falta mas: lo que se prueba es la tabla de
 * tramos, no la de plantilla.
 */
function employeeForTest(int $siteId, string $suffix): int
{
    return DB::table('employees')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $siteId,
        'first_name' => 'Empleado',
        'last_name' => $suffix,
        'employee_code' => 'TEST-'.mb_strtoupper($suffix),
        'status' => 'active',
        'hired_at' => '2025-01-01',
        'locale' => 'es',
        'created_at' => CLOCK_IN,
        'updated_at' => CLOCK_IN,
    ]);
}

/**
 * Inserta un tramo por SQL directo, sin pasar por el dominio.
 *
 * @param  array{site: int, employee: int, other: int}  $fixture
 * @param  array<string, string|int|null>  $overrides
 */
function insertShiftEntry(array $fixture, int $employeeId, array $overrides = []): int
{
    return DB::table('shift_entries')->insertGetId(array_merge([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $fixture['site'],
        'work_date' => '2026-03-02',
        'clocked_in_at' => CLOCK_IN,
        'clocked_out_at' => null,
        'duration_minutes' => null,
        'status' => 'open',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => null,
        'version' => 1,
        'created_at' => CLOCK_IN,
        'updated_at' => CLOCK_IN,
    ], $overrides));
}

/**
 * Un tramo cerrado, que es lo que hace falta en casi todas las pruebas de
 * solape.
 *
 * @param  array{site: int, employee: int, other: int}  $fixture
 * @param  array<string, string|int|null>  $overrides
 */
function insertClosedShiftEntry(array $fixture, int $employeeId, string $out, array $overrides = []): int
{
    return insertShiftEntry($fixture, $employeeId, array_merge([
        'clocked_out_at' => $out,
        'status' => 'closed',
        'clock_out_source' => 'qr_kiosk',
    ], $overrides));
}

/**
 * Comprueba que PostgreSQL rechaza la escritura **y que lo hace por la
 * restriccion que se cree**: sin el nombre, una clave foranea rota o un tipo
 * mal puesto darian la prueba por buena.
 *
 * Va dentro de una transaccion propia —que sobre la de `RefreshDatabase` es un
 * `SAVEPOINT`— porque en PostgreSQL un error aborta la transaccion entera: sin
 * ese punto de retorno, la prueba no podria seguir consultando despues.
 *
 * @param  Closure(): void  $write
 */
function expectRejectionBy(string $constraint, Closure $write): void
{
    try {
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($constraint);

        return;
    }

    Assert::fail(
        'PostgreSQL acepto una fila que «'.$constraint.'» tenia que rechazar. La ultima linea de defensa no esta.',
    );
}

// --- RN-01 · como maximo un turno abierto por empleado -----------------------

it('rechaza por SQL directo un segundo turno abierto del mismo empleado', function (): void {
    $fixture = attendanceFixture();

    insertShiftEntry($fixture, $fixture['employee']);

    expectRejectionBy('one_open_shift_per_employee', function () use ($fixture): void {
        // Otro dia y otra hora: da igual. Lo que RN-01 prohibe es que haya dos
        // tramos sin salida a la vez, no que se solapen.
        insertShiftEntry($fixture, $fixture['employee'], [
            'work_date' => '2026-03-05',
            'clocked_in_at' => '2026-03-05 06:00:00+00',
        ]);
    });
})->group('RN-01');

it('permite un turno abierto por empleado, no uno en toda la instalacion', function (): void {
    $fixture = attendanceFixture();

    insertShiftEntry($fixture, $fixture['employee']);
    insertShiftEntry($fixture, $fixture['other']);

    expect(DB::table('shift_entries')->whereNull('clocked_out_at')->count())->toBe(2);
})->group('RN-01');

it('no cuenta como abierto un turno anulado ni uno sustituido', function (): void {
    // ADR-026: los dos estados no vigentes salen del predicado. Sin esto, un
    // tramo anulado por error bloquearia el fichaje del empleado para siempre.
    $fixture = attendanceFixture();

    insertShiftEntry($fixture, $fixture['employee'], ['status' => 'voided']);
    insertShiftEntry($fixture, $fixture['employee'], ['status' => 'superseded']);

    expect(insertShiftEntry($fixture, $fixture['employee']))->toBeGreaterThan(0);
})->group('RN-01');

// --- RN-02 · los tramos vigentes no se solapan -------------------------------

it('rechaza por SQL directo dos tramos vigentes solapados del mismo empleado', function (): void {
    $fixture = attendanceFixture();

    insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 14:00:00+00', ['duration_minutes' => 480]);

    expectRejectionBy('shift_entries_no_overlap', function () use ($fixture): void {
        insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 18:00:00+00', [
            'clocked_in_at' => '2026-03-02 13:00:00+00',
            'duration_minutes' => 300,
        ]);
    });
})->group('RN-02');

it('acepta dos tramos que se tocan en el instante exacto', function (): void {
    // `tstzrange` usa limites `[)`: el final de un tramo y el principio del
    // siguiente pueden ser el mismo instante. Es la semantica correcta de una
    // jornada partida y de la vuelta de una pausa (ADR-024).
    $fixture = attendanceFixture();

    insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 14:00:00+00', ['duration_minutes' => 480]);

    $second = insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 18:00:00+00', [
        'clocked_in_at' => '2026-03-02 14:00:00+00',
        'duration_minutes' => 240,
    ]);

    expect($second)->toBeGreaterThan(0);
})->group('RN-02');

it('deja fuera del solape los tramos anulados y los sustituidos', function (): void {
    // Es el escenario de la correccion de RN-13: la version anterior se
    // conserva y la nueva ocupa su hueco. Con el predicado en solo `voided`, la
    // primera correccion del producto seria rechazada por la base de datos.
    $fixture = attendanceFixture();

    insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 14:00:00+00', [
        'status' => 'superseded',
        'duration_minutes' => 480,
    ]);

    $corrected = insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 14:00:00+00', [
        'clocked_in_at' => '2026-03-02 06:30:00+00',
        'clock_out_source' => 'manual_admin',
        'duration_minutes' => 450,
        'version' => 2,
    ]);

    expect($corrected)->toBeGreaterThan(0);
})->group('RN-02');

it('deja un turno abierto bloqueando cualquier tramo posterior del mismo empleado', function (): void {
    // Un tramo sin salida es un rango sin limite superior. Que un fichaje
    // posterior choque no es un efecto secundario: es RN-01 y RN-02 diciendo lo
    // mismo desde dos angulos.
    $fixture = attendanceFixture();

    insertShiftEntry($fixture, $fixture['employee']);

    expectRejectionBy('shift_entries_no_overlap', function () use ($fixture): void {
        insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-03 14:00:00+00', [
            'work_date' => '2026-03-03',
            'clocked_in_at' => '2026-03-03 06:00:00+00',
            'duration_minutes' => 480,
        ]);
    });
})->group('RN-02');

it('permite que dos empleados distintos tengan tramos solapados', function (): void {
    // Obvio para una persona y no para una restriccion mal escrita: sin
    // `employee_id WITH =`, el turno de recepcion impediria el de cocina.
    $fixture = attendanceFixture();

    insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 14:00:00+00', ['duration_minutes' => 480]);
    $other = insertClosedShiftEntry($fixture, $fixture['other'], '2026-03-02 14:00:00+00', ['duration_minutes' => 480]);

    expect($other)->toBeGreaterThan(0);
})->group('RN-02');

it('mantiene instalada la extension que sostiene la restriccion de exclusion', function (): void {
    /** @var list<object{extname: string}> $extensions */
    $extensions = DB::select('SELECT extname FROM pg_extension');
    $names = array_map(static fn (object $row): string => $row->extname, $extensions);

    // Sin `btree_gist` no se puede combinar la igualdad de `employee_id` con el
    // solape de `tstzrange`: la restriccion de RN-02 no llegaria a crearse.
    expect($names)->toContain('btree_gist')
        ->and($names)->toContain('pgcrypto')   // Hash del DNI (RL-08).
        ->and($names)->toContain('citext');    // `employee_code` sin ambiguedad de mayusculas.
})->group('RN-02');

// --- RN-03 · la salida es posterior a la entrada -----------------------------

it('rechaza por SQL directo una salida anterior a la entrada', function (): void {
    $fixture = attendanceFixture();

    expectRejectionBy('shift_entries_chk_order', function () use ($fixture): void {
        insertClosedShiftEntry($fixture, $fixture['employee'], '2026-03-02 06:00:00+00', [
            'clocked_in_at' => '2026-03-02 14:00:00+00',
        ]);
    });
})->group('RN-03');

it('rechaza una salida en el mismo instante que la entrada', function (): void {
    // «Estrictamente posterior» (RN-03): un tramo de duracion cero no es un
    // tramo. Es la diferencia entre `>` y `>=`, y es la clase de detalle que
    // acaba en minutos incorrectos en la nomina de alguien.
    $fixture = attendanceFixture();

    expectRejectionBy('shift_entries_chk_order', function () use ($fixture): void {
        insertClosedShiftEntry($fixture, $fixture['employee'], CLOCK_IN);
    });
})->group('RN-03');

it('acepta un tramo sin hora de salida', function (): void {
    // La otra mitad de RN-03: el turno abierto es legitimo y no puede caer en
    // la misma comprobacion.
    $fixture = attendanceFixture();

    expect(insertShiftEntry($fixture, $fixture['employee']))->toBeGreaterThan(0);
})->group('RN-03');

// --- Idempotencia declarativa ------------------------------------------------

it('rechaza por SQL directo un segundo escaneo con el mismo scan_id', function (): void {
    // Cubre solo la mitad DECLARATIVA de RF-AT-07: que el reenvio choque contra
    // el UNIQUE en lugar de duplicar el fichaje. La otra mitad —que el caso de
    // uso devuelva la respuesta original con su mismo codigo de estado— es de
    // la tarea 1.4 y necesita su propia prueba de feature.
    $fixture = attendanceFixture();

    $deviceId = DB::table('devices')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $fixture['site'],
        'name' => 'Quiosco de pruebas',
        'pending_queue_size' => 0,
        'status' => 'active',
        'created_at' => CLOCK_IN,
        'updated_at' => CLOCK_IN,
    ]);

    $scan = [
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $deviceId,
        'employee_id' => $fixture['employee'],
        'occurred_at' => CLOCK_IN,
        'recorded_at' => CLOCK_IN,
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'client_meta' => '{}',
        'worked_minutes' => 0,
    ];

    DB::table('scan_events')->insert($scan);

    expectRejectionBy('scan_events_scan_id_unique', function () use ($scan): void {
        DB::table('scan_events')->insert($scan);
    });
})->group('RF-AT-07');

// --- Valores de serie que viajan con el esquema ------------------------------

it('entrega el perfil de cumplimiento ES-hosteleria con sus cuatro umbrales', function (): void {
    // RF-PD-07: «se entrega el perfil espanol de serie». La fila la escribe la
    // migracion y no un seeder, porque un seeder no se ejecuta en la
    // instalacion de un cliente. La edicion desde el panel es de la tarea 5.1.
    /** @var object{jurisdiction: string, retention_years: int, min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, is_default: bool}|null $profile */
    $profile = DB::table('compliance_profiles')->where('name', 'ES-hosteleria')->first();

    expect($profile)->not->toBeNull()
        ->and($profile?->jurisdiction)->toBe('ES')
        ->and($profile?->min_rest_hours)->toBe(12)              // RN-10, art. 34.3 ET
        ->and($profile?->max_daily_hours)->toBe(9)              // RN-11
        ->and($profile?->break_required_after_hours)->toBe(6)   // RN-12
        ->and($profile?->retention_years)->toBe(4)              // RL-02
        ->and($profile?->is_default)->toBeTrue();
})->group('RF-PD-07');

it('entrega los cuatro umbrales operativos del Anexo B', function (): void {
    // Regla dura 14 y RF-PD-01: ningun valor por defecto vive en el codigo.
    // `OperationalSettings` no tiene constantes; los valores de serie estan
    // aqui, donde el cliente puede cambiarlos sin desplegar nada.
    $settings = DB::table('installation_settings')
        ->where('scope', 'installation')
        ->pluck('value', 'key')
        ->all();

    expect($settings)->toMatchArray([
        'ATTENDANCE_MAX_SHIFT_HOURS' => '12',          // RN-08
        'ATTENDANCE_DEBOUNCE_SECONDS' => '60',         // RF-AT-06
        'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES' => '15',   // RF-AT-10
        'ATTENDANCE_MIN_TRANSIT_SECONDS' => '120',     // RN-16
    ]);
})->group('RF-PD-01');
