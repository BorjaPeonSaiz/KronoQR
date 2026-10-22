<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\WriteLegalExportFile;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Reporting\Infrastructure\Job\GenerateReportExportJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * ¿Cabe la exportacion para la Inspeccion del peor requerimiento en el techo
 * del trabajo de cola? (2.3.0, bloque 14, V5-PA-4; decision del propietario.)
 *
 * El peor caso realista: **500 personas x 4 años** (RL-02) con **dos tramos al
 * dia todos los dias** —pausa partida, sin descanso semanal—, que son
 * 500 x 1461 x 2 = 1.461.000 filas. Sembrar eso en cada pasada de la CI son
 * minutos; aqui se siembra una muestra de 73.000 tramos —200 personas x 365
 * dias—, se mide el ritmo de la escritura de verdad (`WriteLegalExportFile`:
 * cursor de servidor, CSV y avance) y se proyecta.
 *
 * Se exige que la proyeccion quepa en **la mitad** de
 * `GenerateReportExportJob::TIMEOUT_SECONDS` (30 min): el margen x2 es para el
 * servidor del cliente, mas lento que el de la CI, y porque la ordenacion de
 * 1,4 millones de filas no es lineal (sale de `work_mem` a disco). La
 * obsolescencia (`REPORTING_EXPORT_STALE_AFTER`, 60 min) va por encima del
 * techo, asi que un trabajo vivo nunca se da por muerto.
 *
 * Medido el 22-10-2026 en el entorno de desarrollo: 73.000 filas en 2,8 s
 * (unas 26.000 filas/s), es decir, unos 56 s proyectados para el peor caso
 * frente a los 1.800 del techo. No hace falta un techo propio para `legal`.
 *
 * Con `KRONOQR_PRINT_THROUGHPUT=1` imprime la medicion.
 */

uses(CommittedDatabase::class);

const LEGAL_EXPORT_THROUGHPUT_EMPLEADOS = 200;

const LEGAL_EXPORT_THROUGHPUT_DIAS = 365;

/** 500 personas x 1461 dias x 2 tramos. */
const LEGAL_EXPORT_THROUGHPUT_PEOR_CASO = 500 * 1461 * 2;

function muestraParaElRitmoDeLaExportacionLegal(): void
{
    $site = WorkforceFixtures::site('Hotel con cuatro años de registro');
    $utc = new DateTimeZone('UTC');

    for ($i = 0; $i < LEGAL_EXPORT_THROUGHPUT_EMPLEADOS; $i++) {
        $uuid = WorkforceFixtures::employee($site, null, 'active', 'Persona', 'Con Registro', 'T'.$i.Str::random(6));
        $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');
        $rows = [];

        for ($day = 0; $day < LEGAL_EXPORT_THROUGHPUT_DIAS; $day++) {
            $date = (new DateTimeImmutable('2025-01-01', $utc))->modify('+'.$day.' days');

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
                'closed_by' => 'clock_out',
                'version' => 1,
                'created_at' => $date->setTime(6, 0)->format('Y-m-d H:i:sP'),
                'updated_at' => $date->setTime(14, 0)->format('Y-m-d H:i:sP'),
            ];
        }

        DB::table('shift_entries')->insert($rows);
    }

    QueryPlans::analyze('shift_entries', 'employees');
}

it('proyecta el peor requerimiento por debajo de la mitad del techo del trabajo de cola', function (): void {
    muestraParaElRitmoDeLaExportacionLegal();
    $path = sys_get_temp_dir().'/kronoqr-legal-throughput-'.Str::random(8).'.csv';
    $avisos = 0;

    try {
        $start = hrtime(true);

        $tally = app(WriteLegalExportFile::class)->handle(
            LegalExportPeriod::between('2025-01-01', '2025-12-31'),
            LegalExportScope::everyone(),
            $path,
            static function () use (&$avisos): void {
                $avisos++;
            },
        );

        $seconds = (hrtime(true) - $start) / 1e9;
    } finally {
        @unlink($path);
    }

    $rows = $tally->rows();
    $rowsPerSecond = $rows / max($seconds, 0.001);
    $projected = LEGAL_EXPORT_THROUGHPUT_PEOR_CASO / $rowsPerSecond;
    $ceiling = GenerateReportExportJob::TIMEOUT_SECONDS;

    if (getenv('KRONOQR_PRINT_THROUGHPUT') !== false) {
        fwrite(STDERR, sprintf(
            "\nExportacion legal: %d filas en %.2f s (%.0f filas/s). Peor caso %d filas: %.0f s proyectados; techo %d s, obsolescencia %d s.\n",
            $rows,
            $seconds,
            $rowsPerSecond,
            LEGAL_EXPORT_THROUGHPUT_PEOR_CASO,
            $projected,
            $ceiling,
            Config::integer('reporting.export.stale_after_seconds'),
        ));
    }

    expect($rows)->toBe(LEGAL_EXPORT_THROUGHPUT_EMPLEADOS * LEGAL_EXPORT_THROUGHPUT_DIAS)
        // El avance se anuncia cada 500 filas, mas el inicial y el final.
        ->and($avisos)->toBe(intdiv($rows, WriteLegalExportFile::PROGRESS_EVERY_ROWS) + 2)
        ->and($projected)->toBeLessThan($ceiling / 2)
        // Un trabajo vivo nunca se da por muerto antes de su propio techo.
        ->and(Config::integer('reporting.export.stale_after_seconds'))->toBeGreaterThan($ceiling);
})->group('RF-IN-05', 'RNF-P-02');
