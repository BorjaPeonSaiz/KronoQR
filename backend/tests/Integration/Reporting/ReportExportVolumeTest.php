<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Un trimestre de 500 empleados generado en diferido** (**RF-IN-06**, ficha
 * 3.9: «exportacion de 3 meses de 500 empleados en cola, sin agotar memoria»).
 *
 * ## Por que este volumen y no otro
 *
 * Porque es exactamente el caso que el informe sincrono **rechaza**: 92 dias
 * pasan del techo de `reporting.period.max_range_days`, y 500 x 92 = 46.000
 * filas pasan del de `max_rows`. Lo que se comprueba aqui es que el camino en
 * diferido hace lo que el `422` de aquel promete — y que lo hace sin dos cosas
 * que lo harian inservible: sin cargar el fichero en memoria y sin recorrer
 * `daily_totals` entera.
 *
 * ## Las tres afirmaciones
 *
 *   1. **El plan**: `EXPLAIN` de la consulta del informe **sin `Seq Scan on
 *      daily_totals`**. Con cuatro años de retencion (RL-02) esa tabla ronda el
 *      millon de filas, y leerla entera para sacar las 46.000 de un trimestre es
 *      la diferencia entre un informe que tarda segundos y uno que se lleva por
 *      delante la base de datos por la que pasa cada fichaje (RNF-P-02, regla
 *      dura 19).
 *   2. **La memoria**: el pico **adicional** durante la generacion, medido con
 *      `memory_get_peak_usage(true)` antes y despues. El total del proceso no
 *      sirve como afirmacion —la suite arrastra el framework y la conexion— y lo
 *      que se vigila es que **encima** del informe no se construya ademas el
 *      fichero entero en una cadena.
 *   3. **El alcance congelado**: el fichero contiene solo lo que alcanzaba quien
 *      lo pidio **en el momento de pedirlo** (RF-ID-03, decision 1).
 *
 * ## El PDF no entra, y es deliberado
 *
 * Chromium compone el documento entero antes de devolver los bytes: no hay
 * streaming posible y el limite real es otro —el tiempo del navegador—. Un
 * informe de 46.000 filas no se imprime, se abre en una hoja de calculo. Queda
 * dicho aqui para que nadie lea la ausencia como un olvido.
 */

uses(RefreshDatabase::class);

/** La plantilla del Anexo A del doc 02: «virtualizacion para 500 empleados». */
const EMPLEADOS_EN_DIFERIDO = 500;

/** Tres meses: 92 dias, justo por encima del techo sincrono de 92... y del de filas. */
const DESDE_EN_DIFERIDO = '2026-01-01';

const HASTA_EN_DIFERIDO = '2026-04-02';

/**
 * Plantilla, contratos y proyeccion de un trimestre.
 *
 * Las filas de `daily_totals` se escriben con una sola sentencia y no por el
 * agregado: lo que aqui se mide es el camino en diferido, no la coherencia de la
 * proyeccion —eso lo comprueban las pruebas de feature, que si pasan por el
 * agregado— y generar 46.000 jornadas una a una tardaria minutos.
 *
 * @return list<int> los identificadores de los diez departamentos
 */
function plantillaEnDiferido(): array
{
    $site = WorkforceFixtures::site('Hotel con volumen en diferido');

    $departamentos = [];

    for ($i = 0; $i < 10; $i++) {
        $departamentos[] = WorkforceFixtures::department($site, 'Area '.$i);
    }

    $ahora = (string) now();
    $empleados = [];

    for ($i = 0; $i < EMPLEADOS_EN_DIFERIDO; $i++) {
        $empleados[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'department_id' => $departamentos[$i % 10],
            'first_name' => 'Persona',
            // Con acento y con comilla: es lo que rompe un CSV mal codificado y lo
            // que un entrecomillado flojo parte en dos columnas.
            'last_name' => 'Núñez O\'Brien '.$i,
            'employee_code' => 'D'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
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
         CROSS JOIN generate_series(DATE '2026-01-01', DATE '2026-04-02', interval '1 day') AS d
        SQL);

    DB::statement('ANALYZE daily_totals');
    DB::statement('ANALYZE employees');

    return $departamentos;
}

it('genera el trimestre de 500 empleados sin construir el fichero en memoria', function (): void {
    plantillaEnDiferido();
    ReportExports::useTemporaryPath();

    $export = ReportExports::pendingFor(
        ManagementUsers::withRole(UserRole::RRHH)->id,
        from: DESDE_EN_DIFERIDO,
        to: HASTA_EN_DIFERIDO,
    );

    gc_collect_cycles();
    $antes = memory_get_peak_usage(true);

    app(GenerateReportExportHandler::class)->handle($export->uuid);

    $despues = memory_get_peak_usage(true);

    $generada = ReportExports::find($export->uuid);

    expect($generada->status->value)->toBe('completed')
        // 500 personas x 92 dias. Que la cifra sea exacta es la prueba de que en
        // diferido NO se aplican los techos sincronos: este informe habria muerto
        // con `422` por rango y por filas.
        ->and($generada->rowCount)->toBe(EMPLEADOS_EN_DIFERIDO * 92)
        ->and($generada->sizeBytes)->toBeGreaterThan(0)
        ->and(is_file((string) $generada->filePath))->toBeTrue()
        // La huella se calcula sobre el fichero ya cerrado y leido por bloques: es
        // la unica que sirve para comprobar que la descarga llego entera.
        ->and($generada->sha256)->toBe(hash_file('sha256', (string) $generada->filePath));

    /*
     * EL PRESUPUESTO DE MEMORIA ADICIONAL, y lo que cubre exactamente.
     *
     * El informe **si** cabe en memoria a proposito: en diferido no hay techo de
     * filas, asi que 46.000 `PeriodReportRow` y el resultado crudo de PostgreSQL
     * conviven durante la generacion. Eso esta asumido y es la razon por la que
     * el techo es 256 MiB y no 64.
     *
     * Lo que este numero atrapa es la regresion que de verdad ocurre: **un
     * escritor que acumule el fichero en una cadena antes de emitirlo**. Con
     * 46.000 filas el CSV son varios megabytes y el XLSX bastantes mas; un
     * escritor que los componga entero en memoria —ademas del informe— se sale de
     * aqui, y uno que transmita fila a fila no depende del tamaño.
     *
     * Si algun dia el informe en diferido necesitara no caber en memoria, lo que
     * habria que cambiar es el lector —un cursor de servidor, como el de la
     * exportacion integra—, y esta cifra seria la que lo pusiera en evidencia.
     */
    expect($despues - $antes)->toBeLessThan(256 * 1024 * 1024);

    ReportExports::cleanUpTemporaryPath();
})->group('RF-IN-06');

it('no recorre daily_totals entera para sacar el trimestre', function (): void {
    /*
     * Lo que se afirma es **el plan**, no el reloj: un cronometro en una maquina
     * de integracion mide la maquina. Si aparece un `Seq Scan on daily_totals`,
     * falta el acote redundante del `LEFT JOIN` que `DatabasePeriodReportReader`
     * documenta —`dt.work_date BETWEEN ? AND ?`—, y el sintoma en produccion es
     * un informe que tarda minutos y ocupa la base de datos que atiende el
     * cambio de turno.
     *
     * Se ejecuta la forma nuclear de la consulta del informe —la que cruza el
     * calendario con la plantilla y une la proyeccion— en lugar de la SQL entera:
     * lo que decide el plan es ese `LEFT JOIN`, y escribir aqui las cincuenta
     * lineas del informe ataria la prueba a su redaccion.
     */
    plantillaEnDiferido();

    /** @var list<object> $filas */
    $filas = DB::select(<<<'SQL'
        EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
        WITH subjects AS (
            SELECT e.id AS employee_id FROM employees e
        ), calendar AS (
            SELECT generate_series(?::date, ?::date, interval '1 day')::date AS work_date
        ), grid AS (
            SELECT s.employee_id, c.work_date FROM subjects s CROSS JOIN calendar c
        )
        SELECT count(*), COALESCE(sum(dt.total_minutes), 0)
          FROM grid g
          LEFT JOIN daily_totals dt
                 ON dt.employee_id = g.employee_id
                AND dt.work_date = g.work_date
                AND dt.work_date BETWEEN ?::date AND ?::date
        SQL, [DESDE_EN_DIFERIDO, HASTA_EN_DIFERIDO, DESDE_EN_DIFERIDO, HASTA_EN_DIFERIDO]);

    $plan = '';

    foreach ($filas as $fila) {
        // `EXPLAIN` devuelve una columna llamada literalmente «QUERY PLAN», con
        // espacio, que no se puede leer como propiedad con la sintaxis normal.
        $plan .= ((array) $fila)['QUERY PLAN'].PHP_EOL;
    }

    expect($plan)->not->toContain('Seq Scan on daily_totals');
})->group('RF-IN-06');

it('aplica el alcance congelado y no el que tenga la cuenta al generar', function (): void {
    /*
     * RF-ID-03 y decision 1 de la ficha: el trabajo aplica la instantanea de la
     * columna `scope` **tal cual** y nunca la recalcula.
     *
     * Si la releyera, un responsable al que le quitan un departamento entre la
     * peticion y la generacion recibiria un fichero distinto del que pidio —o, al
     * reves, alguien a quien le añaden uno recibiria datos que no podia ver
     * cuando pulso el boton—. Y el asiento de `audit_log`, que se escribio al
     * pedirlo, describiria otra cosa.
     */
    $departamentos = plantillaEnDiferido();
    ReportExports::useTemporaryPath();

    $export = ReportExports::pendingFor(
        ManagementUsers::withRole(UserRole::RRHH)->id,
        from: '2026-01-01',
        to: '2026-01-31',
        // Un solo departamento de los diez: 50 personas de 500.
        scope: AccessScope::forDepartments($departamentos[0]),
    );

    app(GenerateReportExportHandler::class)->handle($export->uuid);

    $generada = ReportExports::find($export->uuid);

    // 50 personas x 31 dias, no 500.
    expect($generada->rowCount)->toBe(50 * 31);

    /*
     * Y el fichero no contiene a nadie de los otros nueve departamentos.
     *
     * Se comprueba por `employee_uuid` y no por el nombre del departamento
     * porque el informe por empleado no lleva ese nombre —lleva
     * `department_id`—, y un identificador numerico suelto dentro de un CSV
     * lleno de cifras no es una afirmacion: coincidiria con cualquier total.
     */
    $contenido = (string) file_get_contents((string) $generada->filePath);

    $dentro = uuidDeEmpleado($departamentos[0]);
    $fuera = uuidDeEmpleado($departamentos[1]);

    expect($contenido)->toContain($dentro)
        ->and($contenido)->not->toContain($fuera);

    ReportExports::cleanUpTemporaryPath();
})->group('RF-IN-06', 'RF-ID-03');

/**
 * El `uuid` publico de cualquier persona de ese departamento.
 *
 * Existe porque `value()` devuelve `mixed` y PHPStan 9 no admite convertirlo a
 * texto a ciegas. Comprobarlo ademas evita el falso verde: una cadena vacia
 * estaria «contenida» en cualquier fichero, y la asercion de alcance pasaria sin
 * haber mirado nada.
 */
function uuidDeEmpleado(int $departmentId): string
{
    $uuid = DB::table('employees')->where('department_id', $departmentId)->value('uuid');

    expect($uuid)->toBeString();

    return \is_string($uuid) ? $uuid : '';
}
