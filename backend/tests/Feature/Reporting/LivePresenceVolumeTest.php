<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabaseAsOwner;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\WallClockBudget;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La mitad servidor de RNF-P-04**: `GET /api/v1/attendance/live` con 500
 * personas dentro y un año de historico detras (tarea 23.4, N5 del bloque 22).
 *
 * RNF-P-04 promete el panel de presencia en vivo con 500 empleados pintado en
 * menos de 1,5 s (LCP). `frontend-admin/tests/e2e/perf-live-presence.spec.ts`
 * mide el pintado con la API SIMULADA: sin esta prueba, nada mide lo que el
 * servidor tarda en dar esas 500 filas, y el LCP del navegador incluye esa
 * espera entera.
 *
 * ## Que se mide
 *
 * La peticion por HTTP, como la hace el panel —autenticacion, `FormRequest`,
 * alcance, licencia, las dos consultas, el asiento de divulgacion de RS-05 y la
 * serializacion de 500 filas—, dentro del proceso de prueba: sin Nginx ni
 * PHP-FPM ni red, que en una instalacion son la misma maquina y una LAN.
 *
 * Cada pasada vacia la cache antes de medir, fuera del reloj: asi cada muestra
 * es una CARGA de la vista, con su asiento de `audit_log`, y no un sondeo que
 * cae dentro de la ventana de agrupacion y se ahorra la escritura.
 *
 * ## Con historico, para que los indices cuenten
 *
 * Con 500 tramos en la tabla cualquier plan es bueno. Se siembran 365 jornadas
 * cerradas por persona —182 500 tramos y 365 000 escaneos— y el tramo abierto de
 * hoy. La consulta tiene que encontrar los 500 abiertos por el indice parcial
 * `one_open_shift_per_employee` y el quiosco de cada uno por
 * `scan_events_clock_in_shift_entry_index`, sin recorrer el historico. Una
 * prueba lo mira en el PLAN, que vale en cualquier maquina; la del reloj va en
 * la suite nocturna de rendimiento (D4, {@see WallClockBudget}). Sin los dos
 * indices de `scan_events` por tramo, la misma peticion tarda 15 s en el
 * anfitrion: es lo que esta prueba existe para cazar.
 *
 * La afirmacion funcional —500 filas en la forma del contrato— no necesita el
 * historico y se siembra sin el: cada siembra con un año cuesta ~35 s, y esta
 * prueba corre en cada push.
 *
 * ## El presupuesto: 200 ms de los 1 500
 *
 * {@see LIVE_PRESENCE_VOLUME_PRESUPUESTO_SEGUNDOS}. El navegador ya gasta lo suyo
 * con la API simulada: la mediana del LCP del E2E es de 416-460 ms en el
 * anfitrion, y la CI va unas 1,36 veces mas lenta (~600 ms). Quedan ~900 ms
 * para la respuesta, la red de la instalacion y un portatil de recepcion mas
 * lento que el que simula el E2E con CPU x4. El servidor se queda con 200 ms,
 * algo mas de una quinta parte de ese margen y el 13 % del total, y el resto es
 * holgura del pintado. Medido en el anfitrion: 23-30 ms por peticion, mediana
 * 25-28 ms; el techo deja un factor 7 para el runner compartido y caza tanto el
 * `Seq Scan` del historico (15 s) como una consulta por fila.
 *
 * ## Por que `RefreshDatabaseAsOwner` en una prueba de feature
 *
 * Por el `ANALYZE` de lo sembrado ({@see QueryPlans::analyze()}), igual que las
 * pruebas de volumen de los informes: sin estadisticas el planificador estima
 * una fila por tabla y el plan no dice nada. La peticion corre en la misma
 * sesion y todo se deshace al terminar.
 */

uses(RefreshDatabaseAsOwner::class);

beforeEach(function (): void {
    // La presencia es funcionalidad accesoria (ADR-023): con licencia se mide la
    // forma de produccion de la respuesta, con el bloque `realtime` completo.
    LicenseKeys::grantAll();

    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
});

/** La plantilla del Anexo A del doc 02 y la cifra de RNF-P-04. */
const LIVE_PRESENCE_VOLUME_EMPLEADOS = 500;

/** Un año de jornadas cerradas por persona, antes de la de hoy. */
const LIVE_PRESENCE_VOLUME_DIAS_DE_HISTORICO = 365;

/** Un hotel con una decena de departamentos (doc 02, Anexo A). */
const LIVE_PRESENCE_VOLUME_DEPARTAMENTOS = 10;

/** El instante de la foto: media mañana, con todo el mundo dentro. */
const LIVE_PRESENCE_VOLUME_AHORA = '2026-03-14 09:12:03';

/**
 * Lo que el servidor puede gastar de los 1,5 s de RNF-P-04, en la mediana:
 * 200 ms, el 13 %. El motivo, en la cabecera del fichero.
 */
const LIVE_PRESENCE_VOLUME_PRESUPUESTO_SEGUNDOS = 0.2;

/**
 * 500 personas en diez departamentos, `$diasDeHistorico` jornadas de 06:00 a
 * 14:00 UTC con sus dos escaneos, y hoy todas dentro desde alguna hora entre las
 * 05:00 y las 08:59 UTC, con el escaneo que abrio el tramo.
 *
 * **Con `INSERT ... SELECT` sobre `generate_series`**, como las demas pruebas de
 * volumen: medio millon de filas construidas en PHP tardarian minutos, y lo que
 * aqui cuenta es lo que hace PostgreSQL con ellas.
 *
 * @return array{token: string}
 */
function presenciaConHistorico(int $diasDeHistorico): array
{
    $site = WorkforceFixtures::site('Hotel de la presencia en vivo', 'Europe/Madrid');
    $device = AttendanceFixtures::device($site, 'Entrada de personal')['id'];

    $departamentos = [];

    for ($i = 0; $i < LIVE_PRESENCE_VOLUME_DEPARTAMENTOS; $i++) {
        $departamentos[] = WorkforceFixtures::department($site, 'Departamento '.$i);
    }

    $ahora = (string) now();
    $empleados = [];

    for ($i = 0; $i < LIVE_PRESENCE_VOLUME_EMPLEADOS; $i++) {
        $empleados[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'department_id' => $departamentos[$i % LIVE_PRESENCE_VOLUME_DEPARTAMENTOS],
            'first_name' => 'Persona',
            'last_name' => 'Apellido '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'employee_code' => 'L'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            'email' => null,
            'status' => 'active',
            'hired_at' => '2024-01-01',
            'terminated_at' => null,
            'locale' => 'es',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    DB::table('employees')->insert($empleados);

    $primerDia = (new DateTimeImmutable('2026-03-14', new DateTimeZone('UTC')))
        ->modify('-'.$diasDeHistorico.' days')
        ->format('Y-m-d');

    // El historico: una jornada cerrada por persona y dia, hasta ayer.
    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, clocked_out_at, duration_minutes,
             status, clock_in_source, clock_out_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, e.site_id, d::date,
               d + interval '6 hours', d + interval '14 hours', 480,
               'closed', 'qr_kiosk', 'qr_kiosk', 1, now(), now()
          FROM employees e
         CROSS JOIN generate_series(?::date, ?::date, interval '1 day') AS d
         ORDER BY d, e.id
        SQL, [$primerDia, '2026-03-13']);

    // Y hoy, todo el mundo dentro: el tramo abierto de cada persona.
    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, status, clock_in_source, version,
             created_at, updated_at)
        SELECT gen_random_uuid(), e.id, e.site_id, DATE '2026-03-14',
               TIMESTAMPTZ '2026-03-14 05:00:00+00' + make_interval(mins => (e.id % 240)::int),
               'open', 'qr_kiosk', 1, now(), now()
          FROM employees e
        SQL);

    // Los escaneos de cada tramo, en orden cronologico como llegan de verdad: la
    // entrada lleva el tramo que abrio, que es de donde sale el quiosco.
    DB::statement(<<<'SQL'
        INSERT INTO scan_events
            (scan_id, device_id, employee_id, occurred_at, recorded_at, origin, intent, result,
             shift_entry_id, worked_minutes, client_meta)
        SELECT gen_random_uuid(), ?, s.employee_id, s.at, s.at, 'qr_kiosk', 'auto', s.result,
               s.shift_entry_id, s.worked_minutes, '{}'::jsonb
          FROM (
                SELECT se.employee_id, se.clocked_in_at AS at, 'clock_in' AS result, se.id AS shift_entry_id,
                       0 AS worked_minutes
                  FROM shift_entries se
                 UNION ALL
                SELECT se.employee_id, se.clocked_out_at, 'clock_out', se.id, 480
                  FROM shift_entries se
                 WHERE se.clocked_out_at IS NOT NULL
               ) AS s
         ORDER BY s.at, s.employee_id
        SQL, [$device]);

    QueryPlans::analyze('employees', 'departments', 'shift_entries', 'scan_events', 'devices');

    FrozenTime::at(LIVE_PRESENCE_VOLUME_AHORA);

    return ['token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH))];
}

it('devuelve a las 500 personas dentro, cada una con su quiosco y en la forma del contrato', function (): void {
    $escenario = presenciaConHistorico(0);
    Spectator::using('openapi.yaml');

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(500)
        ->and($respuesta->json('meta.present_count'))->toBe(500)
        ->and($respuesta->json('meta.absent_count'))->toBe(0)
        ->and(array_unique(array_column((array) $respuesta->json('data'), 'status')))->toBe(['present'])
        // Cada una con el quiosco del escaneo que abrio su tramo de hoy.
        ->and(array_filter(array_column((array) $respuesta->json('data'), 'device')))->toHaveCount(500);
})->group('RNF-P-04', 'RF-PA-01');

it('resuelve las 500 filas sobre un año de historico sin recorrer el de tramos ni el de escaneos', function (): void {
    $escenario = presenciaConHistorico(LIVE_PRESENCE_VOLUME_DIAS_DE_HISTORICO);

    $consultas = QueryPlans::capture(
        static fn (): mixed => Api::as($escenario['token'])
            ->get('/api/v1/attendance/live')
            ->assertOk(),
        static fn (string $sql): bool => str_contains($sql, 'order by lower(e.last_name)'),
    );

    expect($consultas)->toHaveCount(1);

    $root = QueryPlans::root('EXPLAIN (ANALYZE, FORMAT JSON) ', $consultas[0]);

    expect($root)->not->toBeNull('El plan de ejecucion no se pudo leer');
    assert($root !== null);

    $nodos = QueryPlans::flatten($root);

    expect($root['Actual Rows'] ?? null)->toBe(500)
        ->and(QueryPlans::scansSequentially($nodos, 'shift_entries'))->toBeFalse('La presencia recorre shift_entries entera')
        ->and(QueryPlans::scansSequentially($nodos, 'scan_events'))->toBeFalse('La presencia recorre scan_events entera')
        ->and(QueryPlans::usesIndex($nodos, 'scan_events_clock_in_shift_entry_index'))
        ->toBeTrue('El quiosco de origen no sale de scan_events_clock_in_shift_entry_index');
})->group('RNF-P-04', 'RF-PA-01', 'RNF-P-02');

it('sirve la presencia de 500 personas sobre un año de historico dentro de su parte de RNF-P-04, en mediana', function (): void {
    $escenario = presenciaConHistorico(LIVE_PRESENCE_VOLUME_DIAS_DE_HISTORICO);

    $muestras = WallClockBudget::samples(static function () use ($escenario): float {
        // Cada muestra es una carga de la vista, con su asiento de RS-05.
        cache()->flush();

        return WallClockBudget::seconds(
            static fn () => Api::as($escenario['token'])->get('/api/v1/attendance/live')->assertOk(),
        );
    });

    // La cifra queda en el log del job nocturno: es la que se compara con el
    // presupuesto el dia que alguien toque la consulta.
    fwrite(STDERR, \sprintf(
        "RNF-P-04 (servidor): %d presentes en %.3f s de mediana (%s)\n",
        LIVE_PRESENCE_VOLUME_EMPLEADOS,
        WallClockBudget::median($muestras),
        implode(', ', array_map(static fn (float $s): string => \sprintf('%.3f', $s), $muestras)),
    ));

    WallClockBudget::expectMedianBelow($muestras, LIVE_PRESENCE_VOLUME_PRESUPUESTO_SEGUNDOS, 'RNF-P-04');
})->group('RNF-P-04', 'RF-PA-01', 'performance');
