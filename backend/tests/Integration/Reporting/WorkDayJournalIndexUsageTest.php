<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El diario de jornadas de un mes —el detalle del panel y del portal
 * (RF-PA-03)— se sirve ENTERO por indices (RNF-P-02, hallazgos DB1 y DB2 de la
 * 2.1.0).
 *
 * Hasta la 2.2.0, las dos marcas `recorded_at` de cada tramo salian de dos
 * subconsultas correlacionadas sobre `scan_events` que caian en el indice
 * `(device_id, occurred_at)` filtrando `shift_entry_id` a mano —unos 270
 * buffers por tramo—, y la cadena de versiones de `clockingMarks()` hacia `Seq
 * Scan` de `scan_events` en el `JOIN` y de `shift_entries` en el paso recursivo,
 * porque `superseded_by_id` no tenia indice. La pantalla seguia siendo correcta;
 * lo que crecia con los años de retencion (RL-02) era lo que tardaba.
 *
 * Mismo planteamiento que `ScanLogIndexUsageTest`: el SQL se captura del puerto
 * real ({@see QueryPlans}) y el volumen es deliberado. Aqui con escaneos QUE
 * CUELGAN DE SU TRAMO —sin `shift_entry_id` no hay nada que correlacionar— y
 * con tramos corregidos, que son los que hacen trabajar a la consulta
 * recursiva.
 *
 * **Con `CommittedDatabase` y no con `RefreshDatabase`, por el `ANALYZE`.** El
 * rol de la aplicacion no puede analizar tablas que no son suyas —PostgreSQL lo
 * salta con un `WARNING`—, y el rol de migracion, que si puede, va por otra
 * conexion y no ve lo que la transaccion de la prueba no ha confirmado. Sin
 * estadisticas, el planificador decide con heuristicas y esta prueba mediria
 * otra cosa: con ellas, el plan es el de una instalacion de verdad.
 */

uses(CommittedDatabase::class);

/** Empleados del historico. */
const WORK_DAY_JOURNAL_INDEX_EMPLEADOS = 20;

/** Jornadas por empleado: 20 x 250 = 5.000, mas los tramos partidos por pausa y las versiones corregidas. */
const WORK_DAY_JOURNAL_INDEX_DIAS = 250;

/** Escaneos rechazados sin tramo, para que `scan_events` no sea solo lo que cuelga de `shift_entries`. */
const WORK_DAY_JOURNAL_INDEX_RECHAZOS = 10_000;

/**
 * Siembra el historico y devuelve el uuid del empleado cuyo mes se consulta.
 *
 * Cada dia, un turno de 06:00 a 14:00 con su `clock_in` y su `clock_out`. Uno
 * de cada siete, partido por una pausa (ADR-024): dos tramos, el primero
 * cerrado por `break_start` y el segundo abierto por `break_end`. Uno de cada
 * diez, CORREGIDO (RN-13): la version vigente no tiene escaneos y la sustituida
 * —con `superseded_by_id`— conserva los suyos, que es exactamente lo que hace
 * subir a `clockingMarks()` por la cadena.
 */
function diarioConVolumen(): string
{
    $site = WorkforceFixtures::site('Hotel con diario');
    $device = AttendanceFixtures::device($site)['id'];
    $employees = workDayJournalIndexEmployees($site);

    // Primera pasada: los tramos VIGENTES. Se guardan sus escaneos por uuid del
    // tramo, porque el id no se conoce hasta despues de insertar.
    $current = [];
    /** @var array<string, list<array{string, string}>> $scansByEntry */
    $scansByEntry = [];
    /** @var list<array{employee: int, uuid: string, current: string, day: string}> $corrected */
    $corrected = [];

    foreach ($employees as $employeeId) {
        for ($day = 0; $day < WORK_DAY_JOURNAL_INDEX_DIAS; $day++) {
            workDayJournalIndexDay($employeeId, $site, $day, $current, $scansByEntry, $corrected);
        }
    }

    foreach (array_chunk($current, 1_000) as $chunk) {
        DB::table('shift_entries')->insert($chunk);
    }

    $ids = workDayJournalIndexIds();

    // Segunda pasada: las versiones SUSTITUIDAS, apuntando a la vigente y con
    // los escaneos que de verdad se hicieron.
    $superseded = [];

    foreach ($corrected as $correction) {
        $at = static fn (string $time): string => $correction['day'].' '.$time.':00+00';
        $superseded[] = [
            ...workDayJournalIndexEntry($correction['uuid'], $correction['employee'], $site, $correction['day'], $at('06:00'), $at('14:00'), 480),
            'status' => 'superseded',
            'superseded_by_id' => $ids[$correction['current']],
        ];
        $scansByEntry[$correction['uuid']] = [['clock_in', $at('06:00')], ['clock_out', $at('14:00')]];
    }

    foreach (array_chunk($superseded, 1_000) as $chunk) {
        DB::table('shift_entries')->insert($chunk);
    }

    workDayJournalIndexScans($device, $scansByEntry);

    // Estadisticas de verdad, con el rol propietario: ver QueryPlans::analyze().
    QueryPlans::analyze('shift_entries', 'scan_events');

    return array_key_first($employees);
}

/** @return non-empty-array<string, int> uuid => `employees.id` */
function workDayJournalIndexEmployees(int $site): array
{
    $employees = [];

    for ($i = 0; $i < WORK_DAY_JOURNAL_INDEX_EMPLEADOS; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Diario', 'J'.$i.Str::random(6));
        $id = DB::table('employees')->where('uuid', $uuid)->value('id');

        $employees[$uuid] = is_numeric($id)
            ? (int) $id
            : throw new RuntimeException('El empleado sembrado no tiene clave interna.');
    }

    return $employees;
}

/**
 * Una jornada de una persona: el tramo o los tramos vigentes, sus escaneos y,
 * si toca, la correccion que se insertara despues.
 *
 * @param  list<array<string, mixed>>  $current
 * @param  array<string, list<array{string, string}>>  $scansByEntry
 * @param  list<array{employee: int, uuid: string, current: string, day: string}>  $corrected
 */
function workDayJournalIndexDay(int $employeeId, int $site, int $day, array &$current, array &$scansByEntry, array &$corrected): void
{
    $workDate = (new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC')))->modify('+'.$day.' days')->format('Y-m-d');
    $at = static fn (string $time): string => $workDate.' '.$time.':00+00';

    if ($day % 7 === 3) {
        $first = Str::uuid7()->toString();
        $second = Str::uuid7()->toString();
        $current[] = workDayJournalIndexEntry($first, $employeeId, $site, $workDate, $at('06:00'), $at('10:00'), 240);
        $current[] = workDayJournalIndexEntry($second, $employeeId, $site, $workDate, $at('10:30'), $at('14:00'), 210);
        $scansByEntry[$first] = [['clock_in', $at('06:00')], ['break_start', $at('10:00')]];
        $scansByEntry[$second] = [['break_end', $at('10:30')], ['clock_out', $at('14:00')]];

        return;
    }

    $uuid = Str::uuid7()->toString();

    if ($day % 10 === 5) {
        // La version vigente, corregida a mano cinco minutos mas tarde: sin
        // escaneos propios.
        $current[] = workDayJournalIndexEntry($uuid, $employeeId, $site, $workDate, $at('06:00'), $at('14:05'), 485, version: 2);
        $corrected[] = ['employee' => $employeeId, 'uuid' => Str::uuid7()->toString(), 'current' => $uuid, 'day' => $workDate];

        return;
    }

    $current[] = workDayJournalIndexEntry($uuid, $employeeId, $site, $workDate, $at('06:00'), $at('14:00'), 480);
    $scansByEntry[$uuid] = [['clock_in', $at('06:00')], ['clock_out', $at('14:00')]];
}

/**
 * Los escaneos de cada tramo, colgando de su `shift_entry_id`, mas los rechazos
 * sin tramo.
 *
 * @param  array<string, list<array{string, string}>>  $scansByEntry
 */
function workDayJournalIndexScans(int $device, array $scansByEntry): void
{
    $ids = workDayJournalIndexIds();
    $employeeOf = DB::table('shift_entries')->pluck('employee_id', 'uuid')->all();
    $scans = [];

    foreach ($scansByEntry as $entryUuid => $marks) {
        foreach ($marks as [$result, $occurredAt]) {
            $scans[] = workDayJournalIndexScan($device, $employeeOf[$entryUuid], $ids[$entryUuid], $result, $occurredAt);
        }
    }

    for ($i = 0; $i < WORK_DAY_JOURNAL_INDEX_RECHAZOS; $i++) {
        $occurredAt = (new DateTimeImmutable('2024-01-01 05:00:00', new DateTimeZone('UTC')))->modify('+'.$i.' minutes')->format('Y-m-d H:i:sP');
        $scans[] = [
            ...workDayJournalIndexScan($device, null, null, 'rejected_unknown', $occurredAt),
            'worked_minutes' => null,
        ];
    }

    // 4.000 filas por sentencia: el protocolo de PostgreSQL admite 65.535
    // parametros y estas filas tienen catorce columnas.
    foreach (array_chunk($scans, 4_000) as $chunk) {
        DB::table('scan_events')->insert($chunk);
    }
}

/** @return array<string, mixed> */
function workDayJournalIndexEntry(
    string $uuid,
    int $employeeId,
    int $siteId,
    string $workDate,
    string $in,
    string $out,
    int $minutes,
    int $version = 1,
): array {
    return [
        'uuid' => $uuid,
        'employee_id' => $employeeId,
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $in,
        'clocked_out_at' => $out,
        'duration_minutes' => $minutes,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => $version,
        'superseded_by_id' => null,
        'created_at' => $in,
        'updated_at' => $out,
    ];
}

/** @return array<string, mixed> */
function workDayJournalIndexScan(int $deviceId, mixed $employeeId, ?int $shiftEntryId, string $result, string $occurredAt): array
{
    return [
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $deviceId,
        'employee_id' => $employeeId,
        'occurred_at' => $occurredAt,
        // Llega unos segundos despues: es la marca que el diario enseña aparte.
        'recorded_at' => (new DateTimeImmutable($occurredAt))->modify('+3 seconds')->format('Y-m-d H:i:sP'),
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => $result,
        'shift_entry_id' => $shiftEntryId,
        'worked_minutes' => 0,
        'payload_fingerprint' => null,
        'client_meta' => '{}',
        'clock_skew_seconds' => null,
        'flagged_for_review' => false,
    ];
}

/** @return array<string, int> `shift_entries.uuid` => `id` */
function workDayJournalIndexIds(): array
{
    $ids = [];

    foreach (DB::table('shift_entries')->get(['id', 'uuid']) as $row) {
        $ids[(string) $row->uuid] = is_numeric($row->id) ? (int) $row->id : throw new RuntimeException('Tramo sin id.');
    }

    return $ids;
}

it('sirve el diario de un mes entero por indices, sin recorrer scan_events ni shift_entries', function (): void {
    $employeeUuid = diarioConVolumen();
    $reader = app(WorkDayJournalReader::class);

    $journal = null;
    $queries = QueryPlans::capture(
        static function () use ($reader, $employeeUuid, &$journal): void {
            $journal = $reader->journalFor($employeeUuid, 'UTC', DateRange::between('2024-06-01', '2024-06-30'), withIncidents: true);
        },
        static fn (string $sql): bool => (str_contains($sql, 'scan_events') || str_contains($sql, 'shift_entries'))
            && (str_starts_with(ltrim($sql), 'select') || str_starts_with(ltrim($sql), 'with')),
    );

    // Las cinco: jornadas, tramos con sus marcas `recorded_at`, la cadena de
    // versiones, los escaneos de la cadena y las correcciones. Si alguna
    // desaparece o se multiplica, esta prueba ya no esta mirando lo que el
    // diario ejecuta.
    expect($queries)->toHaveCount(5);

    $porTramo = false;
    $porCadena = false;

    foreach ($queries as $query) {
        $nodes = QueryPlans::nodes($query);

        expect($nodes)->not->toBeEmpty('El plan de ejecucion no se pudo leer')
            ->and(QueryPlans::scansSequentially($nodes, 'scan_events'))->toBeFalse("Seq Scan de scan_events en:\n".$query['sql'])
            ->and(QueryPlans::scansSequentially($nodes, 'shift_entries'))->toBeFalse("Seq Scan de shift_entries en:\n".$query['sql']);

        $porTramo = $porTramo || QueryPlans::usesIndex($nodes, 'scan_events_shift_entry_id_occurred_at_index');
        $porCadena = $porCadena || QueryPlans::usesIndex($nodes, 'shift_entries_superseded_by_id_index');
    }

    expect($porTramo)->toBeTrue('Ninguna consulta usa scan_events_shift_entry_id_occurred_at_index')
        ->and($porCadena)->toBeTrue('Ninguna consulta usa shift_entries_superseded_by_id_index');

    // Y el contenido sigue siendo el de antes: la pausa se ve en el tramo que
    // abrio, y el tramo corregido conserva la marca de su version sustituida.
    \assert($journal !== null);
    $entries = array_merge(...array_map(static fn ($day): array => $day->shiftEntries, $journal->days));
    $openedBy = array_count_values(array_map(static fn (JournalShiftEntry $entry): string => $entry->openedBy, $entries));

    expect($openedBy[JournalShiftEntry::OPENED_BY_CLOCK_IN] ?? 0)->toBeGreaterThan(0)
        ->and($openedBy['break_end'] ?? 0)->toBeGreaterThan(0)
        ->and(array_filter($entries, static fn (JournalShiftEntry $entry): bool => $entry->clockInRecordedAt instanceof DateTimeImmutable))->not->toBeEmpty();
})->group('RNF-P-02', 'RF-PA-03', 'RN-13');
