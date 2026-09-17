<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\ComplianceFactsReader;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummaryQuery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseComplianceFactsReader;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El lector de hechos de la vista de cumplimiento con volumen realista
 * (RF-PA-06, RN-10; RNF-P-02).
 *
 * ## Que se comprueba aqui y no en una unitaria
 *
 * Tres cosas que solo existen contra PostgreSQL:
 *
 *   1. **Que `previous_last_out_at` es la ULTIMA jornada anterior**, aunque este
 *      fuera del rango o a semanas de distancia. Es el `lag()` sobre la union de
 *      la ventana con una fila anterior por persona, y es lo que hace que el
 *      primer dia de cualquier ventana se evalue igual que los demas. Un fallo
 *      aqui no rompe nada: el aviso de descanso simplemente no saldria, y nadie
 *      lo notaria.
 *   2. **Que las semanas del borde vienen completas**, incluidos los dias de
 *      fuera del rango pedido (decision 6 de la ficha).
 *   3. **Que el `statement_timeout` corta en el servidor** y sale como el `422`
 *      del informe por periodo, no como un `500`.
 *
 * ## Las filas de `daily_totals` se escriben directamente
 *
 * Mismo criterio que `PeriodReportVolumeTest`: generar 46.000 jornadas por el
 * agregado tardaria horas y lo que aqui se mide es **el plan de PostgreSQL**, no
 * la coherencia de la proyeccion, que comprueban las pruebas de feature.
 */

uses(RefreshDatabase::class);

/** La plantilla del Anexo A del doc 02: «virtualizacion para 500 empleados». */
const EMPLEADOS_DE_CUMPLIMIENTO = 500;

/** El techo sincrono de la vista: 92 dias (`REPORTING_COMPLIANCE_MAX_RANGE_DAYS`). */
const DIAS_DE_CUMPLIMIENTO = 92;

/**
 * 500 empleados en 10 departamentos con jornadas en `[2026-01-01, 2026-04-02]`.
 *
 * **Con huecos a proposito**: solo hay jornada los dias pares del año, asi que
 * entre dos jornadas consecutivas de una persona hay siempre un dia de por medio.
 * Es lo que hace significativa la comprobacion del `lag()`: «el dia anterior» y
 * «la jornada anterior» no son lo mismo, y una consulta que retrocediera un dia
 * de calendario daria `null` en todas.
 *
 * @return array{site: int, departamentos: list<int>}
 */
function volumenDeCumplimiento(): array
{
    $site = WorkforceFixtures::site('Hotel con cumplimiento');

    $departamentos = [];

    for ($i = 0; $i < 10; $i++) {
        $departamentos[] = WorkforceFixtures::department($site, 'Area '.$i);
    }

    $ahora = (string) now();
    $empleados = [];

    for ($i = 0; $i < EMPLEADOS_DE_CUMPLIMIENTO; $i++) {
        $empleados[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'department_id' => $departamentos[$i % 10],
            'first_name' => 'Persona',
            'last_name' => 'Numero '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'employee_code' => 'C'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            'email' => null,
            'status' => 'active',
            'hired_at' => '2025-01-01',
            'terminated_at' => null,
            'locale' => 'es',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    foreach (array_chunk($empleados, 500) as $lote) {
        DB::table('employees')->insert($lote);
    }

    // Jornadas de 09:00 a 17:00 UTC los dias PARES, desde diciembre para que el
    // borde inferior del rango tenga jornada anterior de verdad.
    DB::statement(<<<'SQL'
        INSERT INTO daily_totals
            (employee_id, work_date, total_minutes, shift_count, first_in_at, last_out_at,
             has_open_shift, has_incident, recalculated_at)
        SELECT e.id,
               d::date,
               480,
               1,
               d::date + time '09:00',
               d::date + time '17:00',
               FALSE,
               FALSE,
               now()
          FROM employees e
         CROSS JOIN generate_series(DATE '2025-12-01', DATE '2026-04-30', interval '1 day') AS d
         WHERE extract(day FROM d)::int % 2 = 0
        SQL);

    // Sin esto, el planificador trabaja con las estadisticas de una tabla vacia y
    // elige un plan que no tiene nada que ver con el de produccion.
    DB::statement('ANALYZE daily_totals');
    DB::statement('ANALYZE employees');

    return ['site' => $site, 'departamentos' => $departamentos];
}

function consultaDeCumplimiento(AccessScope $scope, string $from, string $to, ?int $departmentId = null): ComplianceSummaryQuery
{
    return new ComplianceSummaryQuery(
        scope: $scope,
        range: DateRange::between($from, $to),
        departmentId: $departmentId,
        employeeUuid: null,
        rule: null,
    );
}

/**
 * @param  list<ComplianceFacts>  $facts
 * @return list<ComplianceFacts>
 */
function jornadasDe(array $facts, string $employeeUuid): array
{
    return array_values(array_filter(
        $facts,
        static fn (ComplianceFacts $f): bool => $f->employee->uuid === $employeeUuid,
    ));
}

it('carga los hechos de 500 empleados por 92 dias con el descanso resuelto', function (): void {
    volumenDeCumplimiento();

    /** @var ComplianceFactsReader $reader */
    $reader = app(ComplianceFactsReader::class);

    $inicio = microtime(true);

    $facts = $reader->factsFor(
        // Del jueves 1 de enero al jueves 2 de abril: 92 dias, el techo sincrono.
        consultaDeCumplimiento(AccessScope::unrestricted(), '2026-01-01', '2026-04-02'),
        weekStartsOn: 1,
    );

    $segundos = microtime(true) - $inicio;

    // La ventana se amplia a semanas completas: del lunes 29 de diciembre al
    // domingo 5 de abril, que son 47 dias pares. 500 x 47 = 23.500 filas.
    expect($facts)->toHaveCount(23_500)
        // El presupuesto es el `statement_timeout` de la vista (10 s). El plan lo
        // vigila la asercion de abajo; esto traduce el plan a lo que nota quien
        // abre la pantalla.
        ->and($segundos)->toBeLessThan(10.0);

    // Y el descanso esta resuelto en TODAS: la primera jornada de la ventana
    // tiene la suya de diciembre, fuera del rango pedido.
    $sinDescanso = array_values(array_filter(
        $facts,
        static fn (ComplianceFacts $f): bool => $f->previousLastOutAt === null,
    ));

    expect($sinDescanso)->toBe([]);
})->group('RN-10', 'RF-PA-06');

it('resuelve el descanso contra la jornada anterior de verdad y no contra el dia anterior', function (): void {
    // Las jornadas son los dias pares: entre el 2 y el 4 de enero hay un dia sin
    // fichar. Una consulta que retrocediera un dia de calendario daria `null` en
    // todas y RN-10 no saltaria nunca.
    volumenDeCumplimiento();

    /** @var ComplianceFactsReader $reader */
    $reader = app(ComplianceFactsReader::class);

    $facts = $reader->factsFor(
        consultaDeCumplimiento(AccessScope::unrestricted(), '2026-01-01', '2026-01-31'),
        weekStartsOn: 1,
    );

    /** @var string $uuid */
    $uuid = DB::table('employees')->orderBy('id')->value('uuid');

    $unaPersona = jornadasDe($facts, $uuid);

    expect($unaPersona)->not->toBe([]);

    $porFecha = [];

    foreach ($unaPersona as $dia) {
        $porFecha[$dia->workDate] = $dia;
    }

    // El 4 de enero: su jornada anterior es la del 2, no la del 3 —que no existe—.
    expect($porFecha)->toHaveKey('2026-01-04')
        ->and($porFecha['2026-01-04']->previousLastOutAt?->format('Y-m-d H:i'))->toBe('2026-01-02 17:00')
        // Y entre las dos hay 40 h de descanso: 17:00 del 2 a 09:00 del 4.
        ->and($porFecha['2026-01-04']->restMinutes())->toBe(40 * 60);
})->group('RN-10', 'RF-PA-06');

it('trae los dias de fuera del rango que completan las semanas del borde', function (): void {
    // Decision 6 de la ficha: toda semana que toque el rango se evalua sobre sus
    // siete dias. Pidiendo solo el jueves 15 de enero, la ventana tiene que
    // llegar del lunes 12 al domingo 18.
    volumenDeCumplimiento();

    /** @var ComplianceFactsReader $reader */
    $reader = app(ComplianceFactsReader::class);

    $facts = $reader->factsFor(
        consultaDeCumplimiento(AccessScope::unrestricted(), '2026-01-15', '2026-01-15'),
        weekStartsOn: 1,
    );

    $fechas = array_values(array_unique(array_map(
        static fn (ComplianceFacts $f): string => $f->workDate,
        $facts,
    )));

    sort($fechas);

    // Los dias pares del 12 al 18.
    expect($fechas)->toBe(['2026-01-12', '2026-01-14', '2026-01-16', '2026-01-18']);
})->group('RN-17', 'RF-PA-06');

it('mueve la ventana cuando el perfil empieza la semana en domingo', function (): void {
    // El mismo jueves 15 con `week_starts_on = 7` cae en la semana del domingo 11
    // al sabado 17. Es el caso que `date_trunc('week')` no sabe resolver.
    volumenDeCumplimiento();

    /** @var ComplianceFactsReader $reader */
    $reader = app(ComplianceFactsReader::class);

    $facts = $reader->factsFor(
        consultaDeCumplimiento(AccessScope::unrestricted(), '2026-01-15', '2026-01-15'),
        weekStartsOn: 7,
    );

    $fechas = array_values(array_unique(array_map(
        static fn (ComplianceFacts $f): string => $f->workDate,
        $facts,
    )));

    sort($fechas);

    expect($fechas)->toBe(['2026-01-12', '2026-01-14', '2026-01-16']);
})->group('RN-17', 'RF-PA-06');

it('aplica el alcance en el WHERE y no sobre una lista ya traida', function (): void {
    // RF-ID-03. Con un solo departamento tienen que llegar 50 personas de 500, y
    // el recuento de `meta.totals` se calcula sobre esto: filtrar despues
    // describiria a gente que quien pregunta no puede ver.
    $volumen = volumenDeCumplimiento();

    /** @var ComplianceFactsReader $reader */
    $reader = app(ComplianceFactsReader::class);

    $facts = $reader->factsFor(
        consultaDeCumplimiento(
            AccessScope::forDepartments($volumen['departamentos'][0]),
            '2026-01-05',
            '2026-01-11',
        ),
        weekStartsOn: 1,
    );

    $personas = array_unique(array_map(
        static fn (ComplianceFacts $f): string => $f->employee->uuid,
        $facts,
    ));

    expect($personas)->toHaveCount(50);
})->group('RF-ID-03', 'RF-PA-06');

it('devuelve vacio cuando el alcance no alcanza a nadie', function (): void {
    // Un responsable recien creado sin departamentos asignados. El predicado
    // imposible es la traduccion literal de eso; «sin filtro» seria la plantilla
    // entera, que es el fallo que convertiria ese responsable en un administrador.
    volumenDeCumplimiento();

    /** @var ComplianceFactsReader $reader */
    $reader = app(ComplianceFactsReader::class);

    expect($reader->factsFor(
        consultaDeCumplimiento(AccessScope::forDepartments(), '2026-01-05', '2026-01-11'),
        weekStartsOn: 1,
    ))->toBe([]);
})->group('RF-ID-03', 'RF-PA-06');

it('pone el techo de tiempo en el servidor, dentro de la transaccion de lectura', function (): void {
    /*
     * `SET LOCAL statement_timeout` y no un cronometro en PHP: asi la consulta se
     * cancela **en el servidor** y libera la conexion que atiende el fichaje
     * (RNF-P-02, regla dura 19). Medir en PHP el tiempo de algo que ya termino
     * solo sirve para escribirlo en el log, con la base de datos ocupada mientras
     * tanto.
     *
     * `SET LOCAL` y no `SET`: el techo muere con la transaccion. Uno global
     * cortaria migraciones y reconciliaciones que legitimamente tardan mas.
     *
     * Se comprueba la sentencia que sale hacia PostgreSQL —con el valor inyectado
     * por el proveedor, no uno escrito aqui— porque provocar una cancelacion real
     * exigiria un volumen que hiciera la prueba lenta y su resultado dependiente
     * de la maquina. La traduccion del `SQLSTATE 57014` al `422` se comprueba
     * abajo.
     */
    volumenDeCumplimiento();

    $sentencias = [];

    DB::listen(static function (object $query) use (&$sentencias): void {
        /** @var object{sql: string} $query */
        $sentencias[] = $query->sql;
    });

    app(ComplianceFactsReader::class)->factsFor(
        consultaDeCumplimiento(AccessScope::unrestricted(), '2026-01-05', '2026-01-11'),
        weekStartsOn: 1,
    );

    $puestas = array_values(array_filter(
        $sentencias,
        static fn (string $sql): bool => str_contains($sql, 'SET LOCAL statement_timeout'),
    ));

    expect($puestas)->toHaveCount(1)
        ->and($puestas[0])->toBe("SET LOCAL statement_timeout = '10s'")
        // Y va DENTRO de la transaccion: la sentencia siguiente es la consulta.
        ->and($sentencias[array_search($puestas[0], $sentencias, true) + 1] ?? '')
        ->toContain('WITH subjects AS');
})->group('RF-PA-06');

it('traduce la cancelacion de PostgreSQL al 422 del informe y no a un 500', function (): void {
    /*
     * El `SQLSTATE 57014` (`query_canceled`) no es una averia: es el
     * `statement_timeout` haciendo su trabajo. Un `500` mandaria a soporte a
     * buscar un fallo que no existe; quien recibe el `422` tiene algo que cambiar
     * —acortar el rango— y el `detail` se lo dice con las cifras.
     *
     * Se provoca con una conexion que cancela a proposito, en lugar de con un
     * volumen que tarde: asi la prueba dice lo mismo en cualquier maquina.
     */
    $cancelada = new PDOException('SQLSTATE[57014]: Query canceled');
    $cancelada->errorInfo = ['57014', 7, 'canceling statement due to statement timeout'];

    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transaction')->andThrow(
        new QueryException('pgsql', 'SELECT 1', [], $cancelada),
    );

    $reader = new DatabaseComplianceFactsReader($connection, 10);

    expect(fn (): array => $reader->factsFor(
        consultaDeCumplimiento(AccessScope::unrestricted(), '2026-01-01', '2026-01-31'),
        weekStartsOn: 1,
    ))->toThrow(
        ReportTooLargeForSynchronousDelivery::class,
        'La vista de cumplimiento ha superado los 10 segundos y se ha cancelado. '
        .'Reduce el rango o acota el departamento.',
    );
})->group('RF-PA-06');
