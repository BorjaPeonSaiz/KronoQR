<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La exportacion legal de toda la plantilla (RF-IN-05, RL-03) acota el
 * periodo por indice (RNF-P-02, hallazgo DB8 de la 2.1.0).
 *
 * Sin filtro por persona, el unico predicado selectivo es `work_date BETWEEN`,
 * y `shift_entries` no tenia ningun indice que empezara por `work_date`: cada
 * exportacion de una semana recorria cuatro años de tramos (RL-02). Lo sirve
 * ahora `shift_entries_work_date_index`.
 *
 * El SQL se captura del `DECLARE ... CURSOR` que abre el adaptador —es la
 * consulta de verdad, por lotes— y se planifica la consulta del cursor. Mismo
 * planteamiento que `WorkDayJournalIndexUsageTest` para el volumen y el
 * `ANALYZE` ({@see QueryPlans::analyze()}): una semana sobre cientos de dias por
 * persona, para que el indice sea claramente mejor que recorrer la tabla.
 */

uses(CommittedDatabase::class);

/** Empleados del historico: con pocos, el plan va persona a persona por `(employee_id, work_date)` y no mide nada. */
const LEGAL_EXPORT_INDEX_EMPLEADOS = 200;

/** Jornadas por empleado: 200 x 200 = 40.000 tramos. */
const LEGAL_EXPORT_INDEX_DIAS = 200;

function historicoParaExportar(): void
{
    $site = WorkforceFixtures::site('Hotel con historico legal');
    $utc = new DateTimeZone('UTC');
    $rows = [];

    for ($i = 0; $i < LEGAL_EXPORT_INDEX_EMPLEADOS; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Registro', 'L'.$i.Str::random(6));
        $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

        for ($day = 0; $day < LEGAL_EXPORT_INDEX_DIAS; $day++) {
            $date = (new DateTimeImmutable('2024-01-01', $utc))->modify('+'.$day.' days');

            $rows[] = [
                'uuid' => Str::uuid7()->toString(),
                'employee_id' => $employeeId,
                'site_id' => $site,
                'work_date' => $date->format('Y-m-d'),
                'clocked_in_at' => $date->setTime(6, 0)->format('Y-m-d H:i:sP'),
                'clocked_out_at' => $date->setTime(14, 0)->format('Y-m-d H:i:sP'),
                'duration_minutes' => 480,
                'status' => 'closed',
                'clock_in_source' => 'qr_kiosk',
                'clock_out_source' => 'qr_kiosk',
                'version' => 1,
                'created_at' => $date->setTime(6, 0)->format('Y-m-d H:i:sP'),
                'updated_at' => $date->setTime(14, 0)->format('Y-m-d H:i:sP'),
            ];
        }
    }

    foreach (array_chunk($rows, 1_000) as $chunk) {
        DB::table('shift_entries')->insert($chunk);
    }

    QueryPlans::analyze('shift_entries', 'employees');
}

it('acota una semana de toda la plantilla por el indice de work_date', function (): void {
    historicoParaExportar();
    $source = app(LegalExportSource::class);

    $records = 0;
    $declared = QueryPlans::capture(
        // El cursor sin `HOLD` exige transaccion: la abre el caso de uso.
        static function () use ($source, &$records): void {
            DB::transaction(static function () use ($source, &$records): void {
                foreach ($source->records(LegalExportPeriod::between('2024-06-03', '2024-06-09'), LegalExportScope::everyone()) as $record) {
                    $records++;
                }
            });
        },
        static fn (string $sql): bool => str_starts_with(ltrim($sql), 'declare'),
    );

    expect($declared)->toHaveCount(1)
        ->and($records)->toBe(LEGAL_EXPORT_INDEX_EMPLEADOS * 7);

    $query = [
        'sql' => (string) preg_replace('/\A\s*DECLARE\s+\w+\s+NO\s+SCROLL\s+CURSOR\s+FOR\s+/i', '', $declared[0]['sql']),
        'bindings' => $declared[0]['bindings'],
    ];
    $nodes = QueryPlans::nodes($query);

    expect($nodes)->not->toBeEmpty('El plan de ejecucion no se pudo leer')
        ->and(QueryPlans::usesIndex($nodes, 'shift_entries_work_date_index'))->toBeTrue('La exportacion no usa shift_entries_work_date_index')
        ->and(QueryPlans::scansSequentially($nodes, 'shift_entries'))->toBeFalse('La exportacion recorre shift_entries entera');
})->group('RNF-P-02', 'RF-IN-05', 'RL-03');
