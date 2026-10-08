<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\WorkRecordAuditSource;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La pasada diaria de la conciliacion entra por indice en las dos tablas
 * (ADR-057 §4, RNF-P-02).
 *
 * La pasada completa lee las dos tablas enteras y es lo que tiene que hacer; la
 * diaria no puede, porque corre cada noche sobre cuatro años de registro. Lo
 * que se comprueba aqui es que la ventana se resuelve con los indices que ya
 * existen —`shift_entries_work_date_index` y el de accion e instante que cada
 * particion de `audit_log` hereda de `audit_log_action_index`— y que ninguna
 * particion con datos se recorre entera. Ninguno de los dos indices es nuevo:
 * si esta prueba falla, no hace falta un indice, hace falta mirar la consulta.
 *
 * Mismo planteamiento que `LegalExportIndexUsageTest`: el SQL se captura del
 * `DECLARE ... CURSOR` que abre el adaptador, con datos confirmados y
 * estadisticas, y se planifica la consulta del cursor.
 */

uses(CommittedDatabase::class);

/** Empleados del historico. */
const WORK_RECORD_RECONCILIATION_INDEX_EMPLEADOS = 120;

/** Jornadas por empleado, dos tramos cada una: 120 x 150 x 2 = 36 000 tramos y 72 000 asientos. */
const WORK_RECORD_RECONCILIATION_INDEX_DIAS = 150;

function conciliacionHistoricoConAsientos(): void
{
    $site = WorkforceFixtures::site('Hotel con historico conciliado');
    $utc = new DateTimeZone('UTC');
    $entries = [];
    $audits = [];

    for ($i = 0; $i < WORK_RECORD_RECONCILIATION_INDEX_EMPLEADOS; $i++) {
        $employee = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Conciliada', 'W'.$i.Str::random(6));
        $employeeId = DB::table('employees')->where('uuid', $employee)->value('id');

        for ($day = 0; $day < WORK_RECORD_RECONCILIATION_INDEX_DIAS; $day++) {
            $date = new DateTimeImmutable('2026-05-11', $utc)->modify('+'.$day.' days');

            foreach ([8, 13] as $hour) {
                $uuid = Str::uuid7()->toString();
                $in = $date->setTime($hour, 0);
                $out = $in->modify('+4 hours');

                $entries[] = [
                    'uuid' => $uuid,
                    'employee_id' => $employeeId,
                    'site_id' => $site,
                    'work_date' => $date->format('Y-m-d'),
                    'clocked_in_at' => $in->format('Y-m-d H:i:sP'),
                    'clocked_out_at' => $out->format('Y-m-d H:i:sP'),
                    'duration_minutes' => 240,
                    'status' => 'closed',
                    'clock_in_source' => 'qr_kiosk',
                    'clock_out_source' => 'qr_kiosk',
                    'version' => 1,
                    'created_at' => $in->format('Y-m-d H:i:sP'),
                    'updated_at' => $out->format('Y-m-d H:i:sP'),
                ];

                foreach (['shift_entry.created' => $in, 'shift_entry.closed' => $out] as $action => $at) {
                    $audits[] = [
                        'occurred_at' => $at->format('Y-m-d H:i:sP'),
                        'actor_type' => 'device',
                        'action' => $action,
                        'subject_type' => 'shift_entry',
                        'payload' => json_encode([
                            'employee_uuid' => $employee,
                            'shift_entry_uuid' => $uuid,
                            'site_id' => $site,
                            'work_date' => $date->format('Y-m-d'),
                            'clocked_in_at' => $in->format('Y-m-d\TH:i:s.u\Z'),
                            'clocked_out_at' => $out->format('Y-m-d\TH:i:s.u\Z'),
                        ], JSON_THROW_ON_ERROR),
                        // La cadena no importa aqui: lo que se mide es el plan.
                        'hash' => hash('sha256', $uuid.$action),
                    ];
                }
            }
        }
    }

    foreach (array_chunk($entries, 1_000) as $chunk) {
        DB::table('shift_entries')->insert($chunk);
    }

    foreach (array_chunk($audits, 1_000) as $chunk) {
        DB::table('audit_log')->insert($chunk);
    }

    QueryPlans::analyze('shift_entries', 'employees');
    // `audit_log` es particionada: el `ANALYZE` de la madre recorre sus
    // particiones, que es donde el planificador mira.
    DB::connection(config()->string('database.migrations.connection'))->statement('ANALYZE audit_log');
}

it('resuelve la ventana diaria por indice, sin recorrer ninguna particion de audit_log con datos', function (): void {
    conciliacionHistoricoConAsientos();
    $source = resolve(WorkRecordAuditSource::class);
    $window = WorkRecordReconciliationWindow::recent(Instants::utc('2026-10-08 04:15'), 7);

    $pairs = 0;
    $declared = QueryPlans::capture(
        static function () use ($source, $window, &$pairs): void {
            foreach ($source->read($window, 500) as $item) {
                // El primero es el contexto de purgas, no un tramo.
                if ($item instanceof WorkRecordPair) {
                    $pairs++;
                }
            }
        },
        static fn (string $sql): bool => str_starts_with(ltrim($sql), 'declare'),
    );

    // Del 1 al 7 de octubre por la ventana, y el 29 y el 30 de septiembre
    // porque sus asientos caen en los dos dias de margen: nueve jornadas de
    // dos tramos por persona, todas conciliadas.
    expect($declared)->toHaveCount(1)
        ->and($pairs)->toBe(WORK_RECORD_RECONCILIATION_INDEX_EMPLEADOS * 9 * 2);

    $query = [
        'sql' => (string) preg_replace('/\A\s*DECLARE\s+\w+\s+NO\s+SCROLL\s+CURSOR\s+FOR\s+/i', '', $declared[0]['sql']),
        'bindings' => $declared[0]['bindings'],
    ];
    $nodes = QueryPlans::nodes($query);
    $auditIndexes = array_filter(
        $nodes,
        static fn (array $node): bool => \is_string($node['Index Name'] ?? null)
            && str_starts_with($node['Index Name'], 'audit_log_')
            && str_ends_with($node['Index Name'], '_action_occurred_at_idx'),
    );

    expect($nodes)->not->toBeEmpty('El plan de ejecucion no se pudo leer')
        ->and(QueryPlans::usesIndex($nodes, 'shift_entries_work_date_index'))->toBeTrue('La ventana no usa shift_entries_work_date_index')
        ->and($auditIndexes)->not->toBeEmpty('La ventana no entra en audit_log por accion e instante')
        ->and(QueryPlans::scansSequentially($nodes, 'audit_log_2026'))->toBeFalse('La ventana recorre entera la particion de 2026');
})->group('RNF-P-02', 'RL-04', 'RS-07');
