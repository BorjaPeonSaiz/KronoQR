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
use Tests\Support\Time\WallClockBudget;
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
 * La proyeccion se juzga en la suite nocturna de rendimiento, con la mediana
 * de varias pasadas (D4 de la 2.3.0, {@see WallClockBudget}); la suite de
 * integracion comprueba la escritura, sin reloj.
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

/**
 * LA BASE SE DEVUELVE COMO ESTABA, ESTADISTICAS INCLUIDAS.
 *
 * `CommittedDatabase` vacia las tablas con `DELETE`, que deja los 73.000 tramos
 * como filas muertas: unos 12 MB de `shift_entries` hinchada hasta que pase el
 * autovacuum. La prueba siguiente en el orden de la CI,
 * `LegalExportIndexUsageTest`, siembra 40.000 tramos encima y juzga el plan de
 * la consulta, y con la tabla hinchada el planificador puede elegir otro camino
 * (CI 39848984113). Este callback se registra despues del de
 * `CommittedDatabase`, asi que corre cuando la tabla ya esta vacia: la compacta
 * y deja sus estadisticas al dia, con el rol propietario.
 */
function compactarTrasElRitmoDeLaExportacionLegal(): Closure
{
    return static function (): void {
        $owner = DB::connection(config()->string('database.migrations.connection'));

        foreach (['shift_entries', 'employees'] as $table) {
            $owner->statement('VACUUM (FULL, ANALYZE) '.$table);
        }
    };
}

/**
 * Escribe el año de la muestra entera, como el trabajo de cola, y devuelve las
 * filas, los avisos de avance y los segundos que tardo.
 *
 * @return array{rows: int, notices: int, seconds: float}
 */
function escribirLaExportacionLegalDeLaMuestra(): array
{
    $path = sys_get_temp_dir().'/kronoqr-legal-throughput-'.Str::random(8).'.csv';
    $avisos = 0;
    $tally = null;

    try {
        $seconds = WallClockBudget::seconds(static function () use ($path, &$avisos, &$tally): void {
            $tally = app(WriteLegalExportFile::class)->handle(
                LegalExportPeriod::between('2025-01-01', '2025-12-31'),
                LegalExportScope::everyone(),
                $path,
                static function () use (&$avisos): void {
                    $avisos++;
                },
            );
        });
    } finally {
        @unlink($path);
    }

    \assert($tally !== null);

    return ['rows' => $tally->rows(), 'notices' => $avisos, 'seconds' => $seconds];
}

it('exporta la muestra entera con su avance y no da por muerto un trabajo vivo', function (): void {
    // @phpstan-ignore method.notFound (en el cierre de `it()` Pest enlaza `$this` al TestCase de la prueba, que si tiene beforeApplicationDestroyed; la extension de PHPStan lo tipa como TestCall)
    $this->beforeApplicationDestroyed(compactarTrasElRitmoDeLaExportacionLegal());

    muestraParaElRitmoDeLaExportacionLegal();

    $escritura = escribirLaExportacionLegalDeLaMuestra();

    expect($escritura['rows'])->toBe(LEGAL_EXPORT_THROUGHPUT_EMPLEADOS * LEGAL_EXPORT_THROUGHPUT_DIAS)
        // El avance se anuncia cada 500 filas, mas el inicial y el final.
        ->and($escritura['notices'])->toBe(intdiv($escritura['rows'], WriteLegalExportFile::PROGRESS_EVERY_ROWS) + 2)
        // Un trabajo vivo nunca se da por muerto antes de su propio techo.
        ->and(Config::integer('reporting.export.stale_after_seconds'))->toBeGreaterThan(GenerateReportExportJob::TIMEOUT_SECONDS);
})->group('RF-IN-05');

it('proyecta el peor requerimiento por debajo de la mitad del techo del trabajo de cola, en mediana', function (): void {
    // @phpstan-ignore method.notFound (ver la prueba anterior)
    $this->beforeApplicationDestroyed(compactarTrasElRitmoDeLaExportacionLegal());

    muestraParaElRitmoDeLaExportacionLegal();

    // Segundos proyectados para el peor caso en cada pasada: el ritmo medido
    // en la muestra, llevado a 1.461.000 filas.
    $proyecciones = WallClockBudget::samples(static function (): float {
        $escritura = escribirLaExportacionLegalDeLaMuestra();

        return LEGAL_EXPORT_THROUGHPUT_PEOR_CASO / ($escritura['rows'] / max($escritura['seconds'], 0.001));
    });

    $ceiling = GenerateReportExportJob::TIMEOUT_SECONDS;

    if (getenv('KRONOQR_PRINT_THROUGHPUT') !== false) {
        fwrite(STDERR, sprintf(
            "\nExportacion legal: peor caso de %d filas en %.0f s proyectados de mediana; techo %d s, obsolescencia %d s.\n",
            LEGAL_EXPORT_THROUGHPUT_PEOR_CASO,
            WallClockBudget::median($proyecciones),
            $ceiling,
            Config::integer('reporting.export.stale_after_seconds'),
        ));
    }

    WallClockBudget::expectMedianBelow($proyecciones, $ceiling / 2, 'RNF-P-02');
})->group('RF-IN-05', 'RNF-P-02', 'performance');
