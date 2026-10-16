<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La comprobacion de la clave ajena `incidents.shift_entry_id` al borrar tramos
 * entra por `incidents_shift_entry_id_index` (B7 de la 2.2.1, RL-02, RNF-P-02).
 *
 * La clave es `RESTRICT` (regla dura 5): por cada tramo que borra la purga de
 * retencion (`work_record_purge_batch()`), PostgreSQL ejecuta
 * `SELECT 1 FROM ONLY incidents x WHERE shift_entry_id = $1 FOR KEY SHARE OF x`.
 * Sin indice era un `Seq Scan on incidents` por tramo: unos 417 ms por cada mil
 * tramos con el volumen del bloque 7. El SQL de esa comprobacion lo escribe
 * PostgreSQL, no un adaptador, y por eso aqui se transcribe en vez de capturarse.
 *
 * Ademas se MIDE el borrado de mil tramos con y sin el indice, con
 * `EXPLAIN ANALYZE` y en transacciones que se deshacen: el tiempo del disparador
 * de la clave ajena es lo que la purga paga por tramo.
 */

uses(CommittedDatabase::class);

/** Personas del historico. */
const INCIDENT_SHIFT_ENTRY_INDEX_EMPLEADOS = 20;

/** Jornadas por persona: 20 x 500 = 10.000 tramos; la mitad con incidencia. */
const INCIDENT_SHIFT_ENTRY_INDEX_DIAS = 500;

/** Tramos sin hijos que se borran en la medicion. */
const INCIDENT_SHIFT_ENTRY_INDEX_BORRADOS = 1000;

/**
 * Tramos cerrados, uno por persona y dia, e incidencias en los de los dias
 * pares. Devuelve los ids de los tramos SIN incidencia que se pueden borrar.
 *
 * @return list<int>
 */
function tramosConIncidencias(): array
{
    $site = WorkforceFixtures::site('Hotel de la purga');

    for ($i = 0; $i < INCIDENT_SHIFT_ENTRY_INDEX_EMPLEADOS; $i++) {
        AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));
    }

    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, clocked_out_at, duration_minutes,
             status, clock_in_source, clock_out_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, ?, d::date,
               d + interval '6 hours', d + interval '14 hours', 480,
               'closed', 'qr_kiosk', 'qr_kiosk', 1, now(), now()
          FROM employees e
         CROSS JOIN generate_series(DATE '2024-01-01', DATE '2024-01-01' + (? - 1), interval '1 day') AS d
         ORDER BY d, e.id
        SQL, [$site, INCIDENT_SHIFT_ENTRY_INDEX_DIAS]);

    DB::statement(<<<'SQL'
        INSERT INTO incidents
            (employee_id, work_date, shift_entry_id, type, severity, status, detected_at, context,
             created_at, updated_at)
        SELECT se.employee_id, se.work_date, se.id, 'long_shift', 'medium', 'open',
               se.clocked_out_at, '{}'::jsonb, se.clocked_out_at, se.clocked_out_at
          FROM shift_entries se
         WHERE (se.work_date - DATE '2024-01-01') % 2 = 0
        SQL);

    QueryPlans::analyze('incidents', 'shift_entries');

    /** @var list<int> $ids */
    $ids = DB::table('shift_entries')
        ->whereRaw("(work_date - DATE '2024-01-01') % 2 = 1")
        ->orderBy('id')
        ->limit(INCIDENT_SHIFT_ENTRY_INDEX_BORRADOS)
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))
        ->all();

    return $ids;
}

/**
 * Milisegundos que pasa el disparador de `incidents_shift_entry_id_foreign`
 * borrando `$ids`, en una transaccion del rol de migracion que se deshace.
 * Con `$sinIndice`, el indice se retira dentro de la misma transaccion.
 *
 * @param  list<int>  $ids
 */
function msDelDisparadorDeIncidencias(ConnectionInterface $migrador, array $ids, bool $sinIndice): float
{
    $migrador->beginTransaction();

    try {
        if ($sinIndice) {
            $migrador->statement('DROP INDEX incidents_shift_entry_id_index');
        }

        $placeholders = implode(', ', array_fill(0, \count($ids), '?'));
        $filas = $migrador->select('EXPLAIN (ANALYZE, FORMAT JSON) DELETE FROM shift_entries WHERE id IN ('.$placeholders.')', $ids);
        $plan = isset($filas[0]) && \is_object($filas[0]) ? ($filas[0]->{'QUERY PLAN'} ?? null) : null;

        return msDelDisparadorEnElPlan(\is_string($plan) ? $plan : '');
    } finally {
        $migrador->rollBack();
    }
}

/**
 * El `Time` del disparador de `incidents_shift_entry_id_foreign` en el JSON de
 * `EXPLAIN ANALYZE`, o -1 si no aparece.
 */
function msDelDisparadorEnElPlan(string $plan): float
{
    /** @var mixed $decodificado */
    $decodificado = json_decode($plan, true);
    $raiz = \is_array($decodificado) && \is_array($decodificado[0] ?? null) ? $decodificado[0] : [];
    $disparadores = \is_array($raiz['Triggers'] ?? null) ? $raiz['Triggers'] : [];
    $tiempos = array_map(
        static fn (mixed $disparador): mixed => \is_array($disparador) && ($disparador['Constraint Name'] ?? null) === 'incidents_shift_entry_id_foreign'
            ? ($disparador['Time'] ?? null)
            : null,
        $disparadores,
    );

    foreach ($tiempos as $tiempo) {
        if (is_numeric($tiempo)) {
            return (float) $tiempo;
        }
    }

    return -1.0;
}

it('comprueba la clave ajena de incidents por incidents_shift_entry_id_index y no recorriendo la tabla', function (): void {
    $ids = tramosConIncidencias();

    $nodos = QueryPlans::nodes([
        'sql' => 'SELECT 1 FROM ONLY incidents x WHERE shift_entry_id OPERATOR(pg_catalog.=) ? FOR KEY SHARE OF x',
        'bindings' => [$ids[0]],
    ]);

    expect(QueryPlans::usesIndex($nodos, 'incidents_shift_entry_id_index'))->toBeTrue('La comprobacion de la clave ajena no usa incidents_shift_entry_id_index')
        ->and(QueryPlans::scansSequentially($nodos, 'incidents'))->toBeFalse('La comprobacion de la clave ajena recorre incidents entera');
})->group('RNF-P-02', 'RL-02');

it('borrar mil tramos cuesta menos en la clave ajena de incidents con el indice que sin el', function (): void {
    $ids = tramosConIncidencias();
    $migrador = DB::connection(config()->string('database.migrations.connection'));

    expect($ids)->toHaveCount(INCIDENT_SHIFT_ENTRY_INDEX_BORRADOS);

    $con = msDelDisparadorDeIncidencias($migrador, $ids, sinIndice: false);
    $sin = msDelDisparadorDeIncidencias($migrador, $ids, sinIndice: true);

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, \sprintf("B7: clave ajena de incidents al borrar %d tramos: %.1f ms sin indice, %.1f ms con indice\n", \count($ids), $sin, $con));
    }

    // Las dos medidas existen (el disparador se ha leido del plan) y nada se ha
    // borrado de verdad: las dos transacciones se deshicieron.
    expect($con)->toBeGreaterThan(0.0)
        ->and($sin)->toBeGreaterThan($con)
        ->and(DB::table('shift_entries')->whereIn('id', $ids)->count())->toBe(INCIDENT_SHIFT_ENTRY_INDEX_BORRADOS)
        ->and(DB::table('pg_indexes')->where('indexname', 'incidents_shift_entry_id_index')->exists())->toBeTrue();
})->group('RNF-P-02', 'RL-02');
