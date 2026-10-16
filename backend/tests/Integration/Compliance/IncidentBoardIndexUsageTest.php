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
 *
 * **Con tramos detras.** La fila de la bandeja hace `LEFT JOIN shift_entries`
 * por `incidents.shift_entry_id`, y DB6 señalaba dos `Seq Scan`: el de
 * `incidents` y el de `shift_entries`. Con todas las incidencias sin tramo el
 * segundo no se podia ver, asi que la mitad de las incidencias apunta a un tramo
 * sembrado y se afirma que `shift_entries` se lee por su clave primaria y no
 * entera.
 */

uses(CommittedDatabase::class);

/** Empleados del historico de incidencias. */
const INCIDENT_BOARD_INDEX_EMPLEADOS = 20;

/** Jornadas con incidencia por empleado: 20 x 300 = 6.000 incidencias. */
const INCIDENT_BOARD_INDEX_DIAS = 300;

/** Los tipos se reparten para no chocar con `one_incident_per_finding`. */
const INCIDENT_BOARD_INDEX_TIPOS = ['insufficient_rest', 'long_shift', 'short_shift', 'missing_break', 'missing_clock_out', 'clock_skew'];

/**
 * Dos de cada cinco abiertas; el resto, resueltas. Las de los dias pares, con
 * su tramo: 3.000 tramos y 3.000 incidencias que apuntan a uno.
 */
function bandejaConVolumen(): void
{
    $site = WorkforceFixtures::site('Hotel con bandeja');
    $utc = new DateTimeZone('UTC');
    $rows = [];
    $entries = [];

    for ($i = 0; $i < INCIDENT_BOARD_INDEX_EMPLEADOS; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Incidencias', 'B'.$i.Str::random(6));
        $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

        for ($day = 0; $day < INCIDENT_BOARD_INDEX_DIAS; $day++) {
            $date = new DateTimeImmutable('2025-01-01', $utc)->modify('+'.$day.' days');
            $detectedAt = $date->modify('+1 day')->setTime(3, 30, $i)->format('Y-m-d H:i:sP');
            $entryUuid = null;

            if ($day % 2 === 0) {
                $entryUuid = Str::uuid7()->toString();
                $entries[] = incidentBoardIndexEntry($entryUuid, $employeeId, $site, $date);
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'work_date' => $date->format('Y-m-d'),
                'shift_entry_id' => $entryUuid,
                'type' => INCIDENT_BOARD_INDEX_TIPOS[$day % \count(INCIDENT_BOARD_INDEX_TIPOS)],
                'severity' => ['high', 'medium', 'low'][$day % 3],
                // Todas nacen abiertas (ADR-057 §2, `incidents_guard_insert`); las
                // que no lo estan se cierran despues, en una sola sentencia.
                'status' => 'open',
                'assigned_to_user_id' => null,
                'detected_at' => $detectedAt,
                // Del tamaño de uno real (el `context` de `insufficient_rest`
                // del contrato, con sus instantes): con `{}` la fila seria mas
                // estrecha que su entrada de indice y el plan del recuento no
                // diria nada de una bandeja de verdad.
                'context' => '{"rest_minutes": 420, "threshold_minutes": 720, "previous_clock_out": "2025-01-01T22:30:00Z", "next_clock_in": "2025-01-02T05:30:00Z"}',
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'resolution_note' => null,
                'created_at' => $detectedAt,
                'updated_at' => $detectedAt,
            ];
        }
    }

    foreach (array_chunk($entries, 2_000) as $chunk) {
        DB::table('shift_entries')->insert($chunk);
    }

    // `shift_entry_id` llevaba el uuid del tramo; aqui se cambia por su clave.
    $ids = DB::table('shift_entries')->pluck('id', 'uuid')->all();

    foreach ($rows as $index => $row) {
        if (\is_string($row['shift_entry_id'])) {
            $rows[$index]['shift_entry_id'] = $ids[$row['shift_entry_id']];
        }
    }

    foreach (array_chunk($rows, 2_000) as $chunk) {
        DB::table('incidents')->insert($chunk);
    }

    // Cerradas las de los dias 2, 3 y 4 de cada cinco, dos dias despues de su
    // jornada: la misma reparticion que antes se insertaba ya resuelta.
    DB::update(<<<'SQL'
        UPDATE incidents
           SET status = 'resolved',
               resolved_at = (work_date + 2)::timestamp AT TIME ZONE 'UTC',
               resolution_note = 'Revisado con la persona y con su responsable: la salida se fichó al día siguiente.'
         WHERE (work_date - DATE '2025-01-01') % 5 >= 2
        SQL);

    expect(DB::table('incidents')->count())->toBe(INCIDENT_BOARD_INDEX_EMPLEADOS * INCIDENT_BOARD_INDEX_DIAS)
        ->and(DB::table('incidents')->whereNotNull('shift_entry_id')->count())->toBe(INCIDENT_BOARD_INDEX_EMPLEADOS * INCIDENT_BOARD_INDEX_DIAS / 2);

    QueryPlans::analyze('incidents', 'employees', 'shift_entries');
}

/**
 * Un tramo cerrado de 06:00 a 14:00 UTC de la jornada `$date`: uno por persona
 * y dia, asi que no solapa (RN-01).
 *
 * @return array<string, mixed>
 */
function incidentBoardIndexEntry(string $uuid, mixed $employeeId, int $siteId, DateTimeImmutable $date): array
{
    $in = $date->setTime(6, 0)->format('Y-m-d H:i:sP');
    $out = $date->setTime(14, 0)->format('Y-m-d H:i:sP');

    return [
        'uuid' => $uuid,
        'employee_id' => $employeeId,
        'site_id' => $siteId,
        'work_date' => $date->format('Y-m-d'),
        'clocked_in_at' => $in,
        'clocked_out_at' => $out,
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => $in,
        'updated_at' => $out,
    ];
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
        ->and(QueryPlans::scansSequentially($nodes, 'shift_entries'))->toBeFalse('La pagina recorre shift_entries entera para el LEFT JOIN del tramo')
        // El control de que la union existe en el plan: sin el, la asercion de
        // arriba pasaria tambien si el planificador ni tocara la tabla.
        ->and(QueryPlans::usesIndex($nodes, 'shift_entries_pkey'))->toBeTrue('El tramo de cada fila no se lee por shift_entries_pkey')
        ->and(QueryPlans::sortsOver($root, 'incidents'))->toBeFalse('La pagina ordena incidents en memoria: la expresion del ORDER BY ya no es la del indice');

    // Y el orden es el de trabajo: lo urgente primero.
    \assert($page !== null);
    expect($page->rows)->toHaveCount(25)
        ->and($page->rows[0]->incident->severity->value)->toBe('high');
})->with([
    'abiertas' => [IncidentStatus::Open],
    'resueltas' => [IncidentStatus::Resolved],
])->group('RNF-P-02', 'RF-PA-05');

it('cuenta la bandeja sin cruzar employees cuando ningun filtro mira la ficha', function (IncidentStatus $status, bool $soloIndice): void {
    /*
     * El recuento de `meta.total` es exacto (el contrato lo promete y el panel
     * pagina y anuncia con el). Sin alcance ni filtros de persona o
     * departamento no necesita `employees` —la clave ajena de la persona es
     * obligatoria—: la consulta solo toca `incidents`.
     *
     * Las abiertas, que en una bandeja real son la minoria, se cuentan
     * entonces desde un indice sin leer la tabla (`Index Only Scan`; cual de
     * los dos que empiezan por el estado lo decide el planificador). Las
     * resueltas son casi todo el historico y ahi recorrer la tabla puede ser lo
     * barato, asi que no se fija. El mapa de visibilidad lo deja el `VACUUM`,
     * como lo dejaria el autovacuum en produccion.
     */
    bandejaConVolumen();
    QueryPlans::vacuumAnalyze('incidents');
    $board = resolve(IncidentBoard::class);

    $queries = QueryPlans::capture(
        static fn (): mixed => $board->page(new IncidentBoardQuery(AccessScope::unrestricted(), $status)),
        static fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, 'from incidents'),
    );

    expect($queries)->toHaveCount(1);

    $nodes = QueryPlans::nodes($queries[0]);

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, json_encode([$queries[0]['sql'], $nodes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    $relations = array_values(array_unique(array_filter(array_map(
        static fn (array $node): string => \is_string($node['Relation Name'] ?? null) ? $node['Relation Name'] : '',
        $nodes,
    ))));

    expect($relations)->toBe(['incidents']);

    if ($soloIndice) {
        expect(array_any($nodes, static fn (array $node): bool => ($node['Node Type'] ?? '') === 'Index Only Scan'))
            ->toBeTrue('El recuento de las abiertas no sale de un indice')
            ->and(QueryPlans::scansSequentially($nodes, 'incidents'))->toBeFalse('El recuento de las abiertas recorre incidents entera');
    }
})->with([
    'abiertas' => [IncidentStatus::Open, true],
    'resueltas' => [IncidentStatus::Resolved, false],
])->group('RNF-P-02', 'RF-PA-05');

it('con alcance por departamento el recuento sigue cruzando employees, y sin departamentos no pregunta', function (): void {
    bandejaConVolumen();
    $board = resolve(IncidentBoard::class);

    $porDepartamento = QueryPlans::capture(
        static fn (): mixed => $board->page(new IncidentBoardQuery(AccessScope::forDepartments(999_999), IncidentStatus::Open)),
        static fn (string $sql): bool => str_contains($sql, 'count(*)'),
    );

    expect($porDepartamento)->toHaveCount(1)
        ->and(strtolower($porDepartamento[0]['sql']))->toContain('join employees');

    $page = null;
    $aNadie = QueryPlans::capture(
        static function () use ($board, &$page): void {
            $page = $board->page(new IncidentBoardQuery(AccessScope::forDepartments(), IncidentStatus::Open));
        },
        static fn (string $sql): bool => str_contains($sql, 'incidents'),
    );

    \assert($page !== null);
    expect($aNadie)->toBe([])
        ->and($page->total)->toBe(0)
        ->and($page->rows)->toBe([]);
})->group('RF-ID-03', 'RF-PA-05');
