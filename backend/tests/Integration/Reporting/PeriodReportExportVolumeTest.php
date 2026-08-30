<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Http\Response\PeriodReportCsv;
use App\Modules\Reporting\Http\Response\PeriodReportXlsx;
use App\Modules\Reporting\Http\Support\PeriodReportDigest;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La exportacion de un mes de 500 empleados sin agotar la memoria**
 * (**RF-IN-04**, doc 02 §3.1: «no carga en memoria un mes de 500 empleados»).
 *
 * ## Que se mide y por que con datos de verdad
 *
 * El riesgo de una exportacion no es la velocidad, es la **memoria**: un
 * escritor que acumule el fichero en una cadena antes de emitirlo funciona
 * perfectamente con diez filas y tumba el proceso PHP con quince mil. Con un
 * conjunto pequeño, los dos escritores —el que transmite y el que acumula—
 * pasan igual.
 *
 * Se siembra la plantilla del Anexo A —500 personas— y se exporta **un mes con
 * granularidad diaria**, que es el caso grande que el techo sincrono de
 * `config/reporting.php` todavia admite: 500 x 31 = 15.500 filas.
 *
 * ## El presupuesto se expresa en memoria adicional, no en memoria total
 *
 * Lo que se afirma es cuanto crece el pico **durante la escritura del fichero**,
 * medido con `memory_get_peak_usage(true)` antes y despues. El total del proceso
 * no sirve como afirmacion: la suite arrastra el framework, la conexion y el
 * informe ya materializado, y ese suelo cambia con cada version de Laravel.
 *
 * El informe **si** cabe en memoria a proposito y eso no es una contradiccion:
 * lo acota `reporting.period.max_rows`, que es el mismo techo que protege la
 * consulta JSON. Lo que no puede pasar es que **encima** de eso se construya el
 * fichero entero.
 *
 * ## El PDF no entra en esta prueba, y es deliberado
 *
 * Chromium compone el documento entero antes de devolver los bytes: no hay
 * streaming posible y el limite real es otro —el tiempo del navegador—. Un
 * informe de quince mil filas no se imprime, se abre en una hoja de calculo.
 * Queda dicho aqui para que nadie lea la ausencia como un olvido.
 */

uses(RefreshDatabase::class);

/** La plantilla del Anexo A del doc 02: «virtualizacion para 500 empleados». */
const EMPLEADOS_EXPORTADOS = 500;

/**
 * Un mes completo de 500 personas, con su contrato y su proyeccion.
 *
 * Las filas de `daily_totals` se escriben con una sola sentencia y no por el
 * agregado: lo que aqui se mide es el escritor del fichero, no la coherencia de
 * la proyeccion —eso lo comprueban las pruebas de feature, que si pasan por el
 * agregado— y generar quince mil jornadas una a una tardaria minutos.
 *
 * @return array{from: string, to: string}
 */
function volumenDeExportacion(): array
{
    $site = WorkforceFixtures::site('Hotel con volumen de exportacion');

    $departamentos = [];

    for ($i = 0; $i < 10; $i++) {
        $departamentos[] = WorkforceFixtures::department($site, 'Area '.$i);
    }

    $ahora = (string) now();
    $empleados = [];

    for ($i = 0; $i < EMPLEADOS_EXPORTADOS; $i++) {
        $empleados[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'department_id' => $departamentos[$i % 10],
            'first_name' => 'Persona',
            // Con acento y con comilla: es lo que rompe un CSV mal codificado y
            // lo que un entrecomillado flojo parte en dos columnas.
            'last_name' => 'Núñez O\'Brien '.$i,
            'employee_code' => 'X'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            'email' => null,
            'status' => 'active',
            'hired_at' => '2024-01-01',
            'terminated_at' => null,
            'locale' => 'es',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    foreach (array_chunk($empleados, 500) as $lote) {
        DB::table('employees')->insert($lote);
    }

    DB::statement(<<<'SQL'
        INSERT INTO employment_contracts
            (employee_id, weekly_hours, annual_hours, schedule_type, valid_from, valid_to, created_at, created_by_user_id)
        SELECT e.id, 40, NULL, 'turnos', DATE '2024-01-01', NULL, now(), NULL
          FROM employees e
        SQL);

    DB::statement(<<<'SQL'
        INSERT INTO daily_totals
            (employee_id, work_date, total_minutes, shift_count, first_in_at, last_out_at,
             has_open_shift, has_incident, recalculated_at)
        SELECT e.id, d::date, 480, 1, NULL, NULL, FALSE, FALSE, now()
          FROM employees e
         CROSS JOIN generate_series(DATE '2026-01-01', DATE '2026-01-31', interval '1 day') AS d
        SQL);

    DB::statement('ANALYZE daily_totals');
    DB::statement('ANALYZE employees');

    return ['from' => '2026-01-01', 'to' => '2026-01-31'];
}

/**
 * El informe diario de ese mes: 500 x 31 = 15.500 filas.
 */
function informeDeVolumen(): PeriodReport
{
    $volumen = volumenDeExportacion();

    return app(GeneratePeriodReport::class)->handle(
        new PeriodReportQuery(
            scope: AccessScope::unrestricted(),
            range: DateRange::between($volumen['from'], $volumen['to']),
            granularity: ReportGranularity::Day,
            grouping: ReportGrouping::Employee,
            departmentId: null,
            employeeUuid: null,
        ),
        maxRangeDays: 92,
        maxRows: 20000,
        delivery: ReportDelivery::Csv,
    );
}

/**
 * Cuanta memoria adicional consume escribir el fichero, y cuanto ocupa.
 *
 * @param  callable(): void  $write
 * @return array{bytes: int, peakGrowth: int}
 */
function medirEscritura(callable $write): array
{
    // El buffer de salida crece con el fichero y forma parte de lo que se mide:
    // se descuenta al final para que la cifra sea la del ESCRITOR y no la del
    // apaño de la prueba.
    $antes = memory_get_peak_usage(true);

    ob_start();
    $write();
    $contenido = (string) ob_get_clean();

    $despues = memory_get_peak_usage(true);

    return ['bytes' => \strlen($contenido), 'peakGrowth' => max(0, $despues - $antes - \strlen($contenido))];
}

it('exporta a CSV un mes de 500 empleados en streaming, sin agotar la memoria', function (): void {
    App::setLocale('es');

    $informe = informeDeVolumen();
    $huella = PeriodReportDigest::of($informe)->toText();

    $medida = medirEscritura(static function () use ($informe, $huella): void {
        PeriodReportCsv::respond($informe, 'RRHH', $huella)->toResponse()->sendContent();
    });

    // La cifra medida se deja a mano con `KRONOQR_PRINT_PLAN=1`, igual que en la
    // prueba de plan de la 2.8: es el numero que se pega en el informe de la
    // tarea y el que hay que volver a mirar el dia que alguien toque el escritor.
    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, 'RF-IN-04 CSV: '.$informe->rowCount().' filas, '
            .round($medida['bytes'] / 1_048_576, 2).' MiB de fichero, pico +'
            .round($medida['peakGrowth'] / 1_048_576, 2)." MiB\n");
    }

    // Una fila por persona y jornada: la plantilla sembrada por los 31 dias de
    // enero. Se cuenta contra la tabla y no contra la constante para que la
    // afirmacion siga siendo cierta si la semilla de la suite añade a alguien.
    expect($informe->rowCount())->toBe(DB::table('employees')->count() * 31)
        ->and($informe->rowCount())->toBeGreaterThanOrEqual(EMPLEADOS_EXPORTADOS * 31)
        // El fichero es de varios MiB y el pico del escritor no crece con el:
        // ocho megas de holgura sobre un fichero que ocupa mas que eso solo se
        // cumple si las filas salen segun se escriben.
        ->and($medida['bytes'])->toBeGreaterThan(1_000_000)
        ->and($medida['peakGrowth'])->toBeLessThan(8 * 1_048_576);
})->group('RF-IN-04', 'RNF-P-05');

it('exporta a XLSX el mismo mes sin acumular el libro en memoria', function (): void {
    App::setLocale('es');

    $informe = informeDeVolumen();
    $huella = PeriodReportDigest::of($informe)->toText();

    $medida = medirEscritura(static function () use ($informe, $huella): void {
        PeriodReportXlsx::respond($informe, 'RRHH', $huella)->toResponse()->sendContent();
    });

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, 'RF-IN-04 XLSX: '.$informe->rowCount().' filas, '
            .round($medida['bytes'] / 1_048_576, 2).' MiB de fichero, pico +'
            .round($medida['peakGrowth'] / 1_048_576, 2)." MiB\n");
    }

    // El XLSX es un ZIP: ocupa mucho menos que el CSV y aun asi lleva las mismas
    // 15.500 filas. El presupuesto de memoria es mas holgado que el del CSV
    // porque OpenSpout mantiene la tabla de cadenas compartidas y el temporal del
    // archivo, pero sigue sin depender del numero de filas.
    expect($medida['bytes'])->toBeGreaterThan(100_000)
        ->and($medida['peakGrowth'])->toBeLessThan(32 * 1_048_576);
})->group('RF-IN-04', 'RNF-P-05');
