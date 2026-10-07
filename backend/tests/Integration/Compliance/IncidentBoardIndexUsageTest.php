<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\IncidentBoard;
use App\Modules\Compliance\Application\Port\IncidentBoardQuery;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La bandeja de incidencias (RF-PA-05) filtra y ordena por indice, sin
 * ordenar la tabla en memoria (RNF-P-02, hallazgo DB6 de la 2.1.0).
 *
 * La pagina pide «las de este estado, lo urgente primero y dentro de eso lo mas
 * reciente», con un `CASE` sobre la severidad. Hasta la 2.2.0 eso era `Seq Scan
 * on incidents` y un `top-N heapsort` de todas las del estado en cada visita a
 * la bandeja: el unico indice con `status` era el parcial de las abiertas por
 * responsable. Lo sirve ahora `incidents_status_urgency_index`, un indice DE
 * EXPRESION cuya expresion tiene que ser IDENTICA a la del `ORDER BY` de
 * `DatabaseIncidentBoard::ORDER`: si alguien cambia una de las dos, el
 * planificador deja de reconocerla y vuelve el `Sort`. Esta prueba es la que lo
 * detecta.
 *
 * Mismo planteamiento que `WorkDayJournalIndexUsageTest`: SQL capturado del
 * puerto real, volumen deliberado y `ANALYZE` con el rol propietario sobre datos
 * confirmados ({@see QueryPlans::analyze()}).
 */

uses(CommittedDatabase::class);

/** Empleados del historico de incidencias. */
const INCIDENT_BOARD_INDEX_EMPLEADOS = 20;

/** Jornadas con incidencia por empleado: 20 x 300 = 6.000 incidencias. */
const INCIDENT_BOARD_INDEX_DIAS = 300;

/** Los tipos se reparten para no chocar con `one_incident_per_finding`. */
const INCIDENT_BOARD_INDEX_TIPOS = ['insufficient_rest', 'long_shift', 'short_shift', 'missing_break', 'missing_clock_out', 'clock_skew'];

/** Dos de cada cinco abiertas; el resto, resueltas. */
function bandejaConVolumen(): void
{
    $site = WorkforceFixtures::site('Hotel con bandeja');
    $utc = new DateTimeZone('UTC');
    $rows = [];

    for ($i = 0; $i < INCIDENT_BOARD_INDEX_EMPLEADOS; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Incidencias', 'B'.$i.Str::random(6));
        $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

        for ($day = 0; $day < INCIDENT_BOARD_INDEX_DIAS; $day++) {
            $date = new DateTimeImmutable('2025-01-01', $utc)->modify('+'.$day.' days');
            $detectedAt = $date->modify('+1 day')->setTime(3, 30, $i)->format('Y-m-d H:i:sP');
            $open = $day % 5 < 2;

            $rows[] = [
                'employee_id' => $employeeId,
                'work_date' => $date->format('Y-m-d'),
                'shift_entry_id' => null,
                'type' => INCIDENT_BOARD_INDEX_TIPOS[$day % \count(INCIDENT_BOARD_INDEX_TIPOS)],
                'severity' => ['high', 'medium', 'low'][$day % 3],
                'status' => $open ? 'open' : 'resolved',
                'assigned_to_user_id' => null,
                'detected_at' => $detectedAt,
                'context' => '{}',
                'resolved_at' => $open ? null : $date->modify('+2 days')->format('Y-m-d H:i:sP'),
                'resolved_by_user_id' => null,
                'resolution_note' => null,
                'created_at' => $detectedAt,
                'updated_at' => $detectedAt,
            ];
        }
    }

    foreach (array_chunk($rows, 2_000) as $chunk) {
        DB::table('incidents')->insert($chunk);
    }

    expect(DB::table('incidents')->count())->toBe(INCIDENT_BOARD_INDEX_EMPLEADOS * INCIDENT_BOARD_INDEX_DIAS);

    QueryPlans::analyze('incidents', 'employees');
}

it('sirve la pagina de la bandeja por el indice, sin ordenar incidents', function (IncidentStatus $status): void {
    bandejaConVolumen();
    $board = resolve(IncidentBoard::class);

    $page = null;
    $queries = QueryPlans::capture(
        static function () use ($board, $status, &$page): void {
            $page = $board->page(new IncidentBoardQuery(AccessScope::unrestricted(), $status));
        },
        // La de la pagina, no la del recuento: es la que ordena.
        static fn (string $sql): bool => str_contains($sql, 'from incidents') && str_contains($sql, 'limit'),
    );

    expect($queries)->toHaveCount(1);

    $root = QueryPlans::root('EXPLAIN (FORMAT JSON) ', $queries[0]);

    expect($root)->not->toBeNull('El plan de ejecucion no se pudo leer');
    \assert($root !== null);

    $nodes = QueryPlans::flatten($root);

    expect(QueryPlans::usesIndex($nodes, 'incidents_status_urgency_index'))->toBeTrue('La pagina no usa incidents_status_urgency_index')
        ->and(QueryPlans::scansSequentially($nodes, 'incidents'))->toBeFalse('La pagina recorre incidents entera')
        ->and(QueryPlans::sortsOver($root, 'incidents'))->toBeFalse('La pagina ordena incidents en memoria: la expresion del ORDER BY ya no es la del indice');

    // Y el orden es el de trabajo: lo urgente primero.
    \assert($page !== null);
    expect($page->rows)->toHaveCount(25)
        ->and($page->rows[0]->incident->severity->value)->toBe('high');
})->with([
    'abiertas' => [IncidentStatus::Open],
    'resueltas' => [IncidentStatus::Resolved],
])->group('RNF-P-02', 'RF-PA-05');
