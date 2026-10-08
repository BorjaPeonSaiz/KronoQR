<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\PhaseOrder;
use App\Console\Commands\Quality\Support\Requirement;
use App\Console\Commands\Quality\Support\RequirementCatalog;
use App\Console\Commands\Quality\Support\TaggedTest;
use App\Console\Commands\Quality\Support\TagScanner;
use Tests\Architecture\Support\Repo;

/*
 * La puerta de NIVEL de prueba (RQ-14, doc 02 §9.5; T2 de la verificacion de la
 * 2.1.0).
 *
 * ## Lo que ya hacia la CI y lo que no
 *
 * `qa:traceability --check` (RQ-13) falla si un requisito implementado no tiene
 * NINGUNA prueba. Acepta una de cualquier nivel: una regla de negocio cubierta
 * solo por una prueba de feature, o un recorrido de quiosco sin E2E, pasaban en
 * verde. Esta prueba no repite aquella —un requisito sin pruebas lo sigue
 * diciendo `--check`, con su mensaje—: comprueba que las que hay son del nivel
 * que exige la tabla del §9.5.
 *
 * ## Como se decide el nivel exigido
 *
 * La tabla del §9.5 habla de la NATURALEZA de la funcionalidad, y el catalogo
 * de requisitos no la registra. Asi que se declara aqui, una vez por requisito
 * ({@see testLevelGateKinds()}), con los siete tipos de la tabla y uno mas,
 * `sin fila`, para lo que no es ninguno de ellos (un proceso nocturno, el
 * instalador). Los `RN-*` son reglas de negocio por definicion y no hace falta
 * declararlos. **Todo `RF-*` del catalogo tiene que estar clasificado**: uno
 * nuevo sin clasificar rompe la prueba, y clasificarlo es decidir sus niveles a
 * la vista, en revision, y no a criterio de quien lo implementa.
 *
 * ## Como se decide el nivel de una prueba
 *
 * Por donde vive: `tests/Unit`, `tests/Integration`, `tests/Feature`,
 * `tests/Contract`, Playwright. **El contrato no es solo `tests/Contract`**: una
 * prueba de feature que valida su respuesta contra `openapi.yaml` con Spectator
 * (`assertValidResponse`) cubre la columna «Feature + Contrato» igual que una
 * prueba de contrato (R1-QA-02: contarlo por la carpeta daba 44 huecos falsos).
 *
 * ## Lo que NO comprueba, y donde se comprueba
 *
 * - **La autorizacion negativa por cada rol** del tipo `endpoint`. No se puede
 *   atribuir a un requisito —la matriz no lleva su etiqueta— y no hace falta: la
 *   comprueba ruta a ruta `Tests\Feature\AuthorizationMatrixTest`, que falla si
 *   una ruta del router no esta en la matriz.
 * - **La idempotencia concurrente** de la escritura del quiosco: es RQ-03, con su
 *   propia trazabilidad.
 */

const TEST_LEVEL_GATE_UNITARIA = 'unitaria';

const TEST_LEVEL_GATE_INTEGRACION = 'integracion';

const TEST_LEVEL_GATE_FEATURE = 'feature';

const TEST_LEVEL_GATE_CONTRATO = 'contrato';

const TEST_LEVEL_GATE_E2E = 'e2e';

/**
 * Los tipos de la tabla del §9.5 y los niveles que exige cada uno.
 *
 * @return array<string, list<string>>
 */
function testLevelGateLevelsByKind(): array
{
    return [
        'regla de negocio' => [TEST_LEVEL_GATE_UNITARIA],
        'esquema' => [TEST_LEVEL_GATE_INTEGRACION],
        'endpoint' => [TEST_LEVEL_GATE_FEATURE, TEST_LEVEL_GATE_CONTRATO],
        'recorrido' => [TEST_LEVEL_GATE_E2E],
        'escritura del quiosco' => [TEST_LEVEL_GATE_UNITARIA, TEST_LEVEL_GATE_INTEGRACION, TEST_LEVEL_GATE_FEATURE, TEST_LEVEL_GATE_CONTRATO, TEST_LEVEL_GATE_E2E],
        'informe' => [TEST_LEVEL_GATE_UNITARIA, TEST_LEVEL_GATE_INTEGRACION, TEST_LEVEL_GATE_FEATURE, TEST_LEVEL_GATE_CONTRATO],
        'configuracion del calculo' => [TEST_LEVEL_GATE_UNITARIA, TEST_LEVEL_GATE_INTEGRACION, TEST_LEVEL_GATE_FEATURE, TEST_LEVEL_GATE_CONTRATO],
        'sin fila' => [],
    ];
}

/**
 * La naturaleza de cada requisito funcional, en los terminos del §9.5.
 *
 * Un requisito puede ser de varios tipos —un endpoint con recorrido de usuario—
 * y entonces se le exigen los niveles de todos. Los `RN-*` no se declaran: son
 * `regla de negocio`. Si se anade aqui uno, es para exigirle algo mas.
 *
 * `sin fila` lleva su motivo en el comentario: es lo que el §9.5 deja sin
 * casilla, y `--check` le sigue exigiendo al menos una prueba.
 *
 * @return array<string, list<string>>
 */
function testLevelGateKinds(): array
{
    $regla = 'regla de negocio';
    $esquema = 'esquema';
    $endpoint = 'endpoint';
    $recorrido = 'recorrido';
    $quiosco = 'escritura del quiosco';
    $informe = 'informe';
    $calculo = 'configuracion del calculo';
    // Procesos de fondo, de instalacion o de salida al fabricante: no exponen un
    // endpoint ni tienen pantalla, y su prueba de verdad es la de integracion o
    // la de instalacion (RQ-11), que `--check` ya exige que exista.
    $sinFila = 'sin fila';

    return [
        // Fichaje
        'RF-AT-01' => [$quiosco],
        'RF-AT-02' => [$regla, $endpoint],
        'RF-AT-03' => [$regla, $endpoint],
        'RF-AT-04' => [$regla],
        'RF-AT-05' => [$endpoint, $recorrido],
        'RF-AT-06' => [$calculo],
        'RF-AT-07' => [$quiosco],
        'RF-AT-08' => [$regla, $endpoint],
        'RF-AT-09' => [$regla, $endpoint],
        'RF-AT-10' => [$endpoint, $recorrido],
        'RF-AT-11' => [$quiosco],
        'RF-AT-12' => [$quiosco],

        // Plantilla
        'RF-GP-01' => [$endpoint, $recorrido],
        'RF-GP-02' => [$endpoint, $recorrido],
        'RF-GP-03' => [$endpoint, $recorrido],
        'RF-GP-04' => [$endpoint, $recorrido],
        'RF-GP-05' => [$endpoint, $recorrido],

        // Identidad y acceso
        'RF-ID-01' => [$endpoint, $recorrido],
        'RF-ID-02' => [$endpoint],
        'RF-ID-03' => [$endpoint, $recorrido],
        'RF-ID-04' => [$endpoint],
        'RF-ID-05' => [$endpoint, $recorrido],
        'RF-ID-06' => [$endpoint, $recorrido],
        'RF-ID-07' => [$endpoint],
        'RF-ID-08' => [$endpoint],
        'RF-ID-09' => [$endpoint, $recorrido],
        'RF-ID-10' => [$endpoint, $recorrido],

        // Quiosco
        'RF-KI-01' => [$recorrido],
        'RF-KI-02' => [$recorrido],
        'RF-KI-03' => [$recorrido],
        'RF-KI-04' => [$quiosco],
        'RF-KI-05' => [$recorrido],
        'RF-KI-06' => [$recorrido],
        'RF-KI-07' => [$recorrido],
        'RF-KI-08' => [$endpoint, $recorrido],
        'RF-KI-09' => [$recorrido],

        // Credenciales
        'RF-QR-01' => [$endpoint],
        'RF-QR-02' => [$regla, $endpoint],
        'RF-QR-03' => [$endpoint],
        'RF-QR-04' => [$endpoint],
        // El nivel de correccion del QR se comprueba sobre la tarjeta impresa y
        // escaneada de verdad por la camara simulada.
        'RF-QR-05' => [$recorrido],
        'RF-QR-06' => [$endpoint],
        'RF-QR-07' => [$endpoint],
        'RF-QR-08' => [$endpoint, $recorrido],

        // Informes
        'RF-IN-01' => [$informe, $recorrido],
        'RF-IN-02' => [$informe, $recorrido],
        'RF-IN-03' => [$informe, $recorrido],
        'RF-IN-04' => [$informe, $recorrido],
        'RF-IN-05' => [$informe],
        'RF-IN-06' => [$informe, $recorrido],
        'RF-IN-07' => [$informe, $recorrido],
        'RF-IN-08' => [$informe, $recorrido],

        // Panel
        'RF-PA-01' => [$endpoint, $recorrido],
        'RF-PA-02' => [$endpoint, $recorrido],
        'RF-PA-03' => [$endpoint, $recorrido],
        'RF-PA-04' => [$endpoint, $recorrido],
        'RF-PA-05' => [$endpoint, $recorrido],
        'RF-PA-06' => [$endpoint, $recorrido],
        'RF-PA-07' => [$endpoint, $recorrido],

        // Procesos
        'RF-PR-01' => [$regla],
        'RF-PR-02' => [$sinFila],
        'RF-PR-03' => [$sinFila],
        'RF-PR-04' => [$sinFila],
        'RF-PR-05' => [$informe],
        'RF-PR-06' => [$regla],

        // Producto
        'RF-PD-01' => [$calculo],
        'RF-PD-02' => [$sinFila],
        'RF-PD-03' => [$endpoint, $recorrido],
        'RF-PD-04' => [$endpoint, $recorrido],
        'RF-PD-05' => [$endpoint],
        'RF-PD-06' => [$endpoint, $recorrido],
        'RF-PD-07' => [$calculo, $recorrido],
        'RF-PD-08' => [$endpoint, $recorrido],
        'RF-PD-09' => [$endpoint, $recorrido],
        'RF-PD-10' => [$sinFila],
        'RF-PD-11' => [$endpoint, $recorrido],
        'RF-PD-12' => [$sinFila],
        'RF-PD-13' => [$endpoint],
        'RF-PD-14' => [$informe, $recorrido],
        'RF-PD-15' => [$endpoint, $recorrido],

        // Reglas que ademas viven en una restriccion de la base de datos: la
        // ultima linea de defensa se prueba contra PostgreSQL de verdad.
        'RN-01' => [$regla, $esquema],
        'RN-02' => [$regla, $esquema],
        'RN-03' => [$regla, $esquema],
        'RN-13' => [$regla, $esquema],
    ];
}

/**
 * LOS HUECOS CONOCIDOS, lista cerrada y con motivo.
 *
 * La prueba exige que los huecos que encuentra sean EXACTAMENTE estos: uno nuevo
 * rompe la CI, y uno que se cierra tambien, para que se quite de aqui y no se
 * quede de excusa para el siguiente.
 *
 * @return array<string, array<string, string>>
 */
function testLevelGateKnownGaps(): array
{
    return [
        'RF-IN-02' => [
            // El agregado por departamento lo calcula PostgreSQL
            // (`DatabasePeriodReportReader`, `GROUP BY` del informe): no hay
            // calculo de dominio que probar sin base de datos. Su prueba del
            // calculo es la de integracion con volumen, `PeriodReportVolumeTest`.
            TEST_LEVEL_GATE_UNITARIA => 'el agregado lo calcula la consulta SQL, no el dominio',
        ],
        // Los dos que destapo conceder el contrato por prueba y no por fichero
        // (bloque 14): tenian el nivel prestado de pruebas vecinas.
        'RF-PR-05' => [
            // El resumen semanal es un proceso programado que envia correo: no
            // tiene endpoint propio cuya respuesta validar. Su unica superficie
            // HTTP es el ajuste `WEEKLY_SUMMARY_EMAIL` de `PATCH /settings`.
            // Pendiente de decidir si se reclasifica como `sin fila` o se
            // valida el contrato de ese ajuste en una prueba etiquetada.
            TEST_LEVEL_GATE_CONTRATO => 'proceso programado sin endpoint propio; la reclasificacion esta pendiente',
        ],
        'RF-QR-04' => [
            // Ninguna prueba valida contra `openapi.yaml` la respuesta de
            // `POST /credentials/{uuid}/print` ni `/credentials/print-batch`
            // (un PDF que renderiza Chromium). Las de la disposicion de la
            // tarjeta van contra el renderizador, no contra el endpoint.
            TEST_LEVEL_GATE_CONTRATO => 'falta validar contra el contrato la respuesta PDF de la impresion',
        ],
    ];
}

/**
 * El arbol de pruebas, explorado una sola vez con el mismo extractor que
 * `qa:traceability` y sobre los mismos directorios que `quality.test_paths`.
 *
 * @return array<string, list<TaggedTest>>
 */
function testLevelGateTestsByRequirement(): array
{
    static $index = null;

    if ($index !== null) {
        return $index;
    }

    $backend = \dirname(__DIR__, 2);

    $scan = (new TagScanner([
        'pest' => [$backend.'/tests'],
        'playwright' => [
            Repo::file('frontend-kiosk/tests/e2e'),
            Repo::file('frontend-admin/tests/e2e'),
            Repo::file('frontend-portal/tests/e2e'),
        ],
        'k6' => [Repo::file('load-tests/k6')],
    ], \dirname($backend)))->scan();

    return $index = $scan->byRequirement();
}

/**
 * Los niveles de una prueba: el de su carpeta y, si es de feature y valida contra
 * el contrato, tambien `contrato`.
 *
 * @return list<string>
 */
function testLevelGateLevelsOf(TaggedTest $test): array
{
    $level = match (true) {
        $test->tool === 'playwright' => TEST_LEVEL_GATE_E2E,
        $test->tool !== 'pest' => '',
        str_contains($test->file, '/tests/Unit/') => TEST_LEVEL_GATE_UNITARIA,
        str_contains($test->file, '/tests/Integration/') => TEST_LEVEL_GATE_INTEGRACION,
        str_contains($test->file, '/tests/Contract/') => TEST_LEVEL_GATE_CONTRATO,
        str_contains($test->file, '/tests/Feature/') => TEST_LEVEL_GATE_FEATURE,
        default => '',
    };

    return array_values(array_filter([
        $level,
        $level === TEST_LEVEL_GATE_FEATURE && testLevelGateValidatesContract($test) ? TEST_LEVEL_GATE_CONTRATO : '',
    ]));
}

/**
 * ¿Valida ESTA prueba su respuesta contra `openapi.yaml`?
 *
 * **Por prueba, no por fichero** (revision del bloque 14): con el fichero
 * entero, un solo `assertValidResponse` le daba el contrato a todas las pruebas
 * de al lado, validaran o no. Al pasar a por prueba salieron dos requisitos que
 * solo tenian el contrato prestado (ver {@see testLevelGateKnownGaps()}).
 *
 * El cuerpo de la prueba va desde su `it(`/`test(` hasta la linea de su
 * etiqueta, que es donde la situa el escaner. Cuenta tambien la llamada a una
 * funcion **del mismo fichero** que valida (`ficharConDesfase()` en
 * `ClockSkewIncidentTest`), porque es como se escriben muchas: un nivel de
 * indireccion y solo en el fichero. Un ayudante de `tests/Support` que validara
 * no contaria; hoy no hay ninguno.
 */
function testLevelGateValidatesContract(TaggedTest $test): bool
{
    static $files = [];

    $file = \dirname(__DIR__, 3).'/'.$test->file;
    $files[$file] ??= testLevelGateContractShapeOf((string) @file_get_contents($file));

    $preceding = array_slice($files[$file]['lines'], 0, $test->line);
    $starts = array_keys(array_filter($preceding, static fn (string $line): bool => preg_match('/^\s*(it|test)\(/', $line) === 1));
    $body = implode("\n", array_slice($preceding, $starts === [] ? 0 : max($starts)));

    $calls = array_filter(
        $files[$file]['validatingHelpers'],
        static fn (string $helper): bool => preg_match('/\b'.preg_quote($helper, '/').'\(/', $body) === 1,
    );

    return str_contains($body, 'assertValidResponse') || $calls !== [];
}

/**
 * Las lineas de un fichero de pruebas y sus funciones de primer nivel que
 * validan contra el contrato.
 *
 * @return array{lines: list<string>, validatingHelpers: list<string>}
 */
function testLevelGateContractShapeOf(string $source): array
{
    preg_match_all('/^function\s+(\w+)\s*\(.*?^\}/ms', $source, $functions, PREG_SET_ORDER);

    $helpers = array_values(array_map(
        static fn (array $function): string => $function[1],
        array_filter($functions, static fn (array $function): bool => str_contains($function[0], 'assertValidResponse')),
    ));

    return ['lines' => explode("\n", $source), 'validatingHelpers' => $helpers];
}

/**
 * Los requisitos cuya fase ya se ha ejecutado, con el mismo criterio que
 * `--check`: el orden y la fase en curso de `config/quality.php`, leidos como
 * literales.
 *
 * @return list<Requirement>
 */
function testLevelGateRequirementsInScope(): array
{
    $config = Repo::contents('backend/config/quality.php');

    preg_match('/\'current_phase\'\s*=>\s*(\d+)/', $config, $current);
    preg_match('/\'phase_execution_order\'\s*=>\s*\[([\d,\s]+)\]/', $config, $order);

    // Si la forma de `config/quality.php` cambia y las expresiones dejan de
    // casar, el alcance quedaria vacio y la puerta pasaria en verde sin mirar
    // nada: se revienta aqui, con el motivo.
    $phaseList = $order[1] ?? throw new RuntimeException('No se lee phase_execution_order de config/quality.php.');
    $currentPhase = $current[1] ?? throw new RuntimeException('No se lee current_phase de config/quality.php.');

    $phases = array_map(intval(...), array_map(trim(...), explode(',', $phaseList)));

    return RequirementCatalog::fromFile(Repo::file('docs/requisitos.yaml'))
        ->inScope(new PhaseOrder($phases), (int) $currentPhase);
}

/**
 * Lo que el §9.5 exige a un requisito y no tiene: `requisito => [nivel => true]`.
 *
 * @return array<string, list<string>>
 */
function testLevelGateMissingLevels(): array
{
    $kinds = testLevelGateKinds();
    $levelsByKind = testLevelGateLevelsByKind();
    $tests = testLevelGateTestsByRequirement();
    $missing = [];

    foreach (testLevelGateRequirementsInScope() as $requirement) {
        $declared = $kinds[$requirement->id] ?? (str_starts_with($requirement->id, 'RN-') ? ['regla de negocio'] : []);
        $required = array_unique(array_merge(...array_map(static fn (string $kind): array => $levelsByKind[$kind], $declared)));
        $present = array_unique(array_merge([], ...array_map(testLevelGateLevelsOf(...), $tests[$requirement->id] ?? [])));

        $lacking = array_values(array_diff($required, $present));
        sort($lacking);
        $missing[$requirement->id] = $lacking;
    }

    return array_filter($missing);
}

it('clasifica en un tipo del §9.5 cada requisito funcional del catalogo', function (): void {
    $functional = array_values(array_filter(
        array_map(static fn (Requirement $r): string => $r->id, RequirementCatalog::fromFile(Repo::file('docs/requisitos.yaml'))->requirements),
        static fn (string $id): bool => str_starts_with($id, 'RF-'),
    ));

    expect(array_values(array_diff($functional, array_keys(testLevelGateKinds()))))->toBe(
        [],
        'Requisito(s) funcional(es) sin clasificar en testLevelGateKinds(): decide su tipo segun la tabla del '
        .'doc 02 §9.5 y los niveles de prueba saldran de ahi (RQ-14).',
    );
})->group('RQ-14');

it('no clasifica requisitos que el catalogo no tiene ni con tipos que la tabla no conoce', function (): void {
    $catalog = array_map(static fn (Requirement $r): string => $r->id, RequirementCatalog::fromFile(Repo::file('docs/requisitos.yaml'))->requirements);
    $kinds = array_unique(array_merge(...array_values(testLevelGateKinds())));

    expect(array_values(array_diff(array_keys(testLevelGateKinds()), $catalog)))->toBe([])
        ->and(array_values(array_diff($kinds, array_keys(testLevelGateLevelsByKind()))))->toBe([]);
})->group('RQ-14');

it('exige a cada requisito implementado los niveles de prueba de su tipo', function (): void {
    $known = array_map(static function (array $levels): array {
        $names = array_keys($levels);
        sort($names);

        return $names;
    }, testLevelGateKnownGaps());

    $missing = testLevelGateMissingLevels();
    ksort($known);
    ksort($missing);

    expect($missing)->toBe(
        $known,
        'Los huecos de nivel no son los de la lista cerrada testLevelGateKnownGaps(). Si sobra uno, escribe '
        .'la prueba del nivel que falta (doc 02 §9.5); si se ha cerrado uno, quitalo de la lista.',
    );
})->group('RQ-14');

it('deja en alcance los requisitos de las fases ya ejecutadas', function (): void {
    // El control del alcance: con cero requisitos, la puerta de los huecos
    // pasaria en verde sin comprobar nada. Hoy son 167 (todas las fases menos
    // la 4, que es la en curso detras de la 3); 150 es un suelo holgado, y si
    // baja de ahi algo ha dejado de leerse.
    expect(\count(testLevelGateRequirementsInScope()))->toBeGreaterThan(150);
})->group('RQ-14');

it('explora el arbol de pruebas de todos los niveles', function (): void {
    // El control: si el extractor no viera nada, la prueba de arriba diria que
    // falta todo y nadie sabria por que. Y si no viera Playwright —un montaje
    // que falta—, los recorridos saldrian como huecos falsos.
    $levels = array_unique(array_merge([], ...array_map(
        testLevelGateLevelsOf(...),
        array_merge(...array_values(testLevelGateTestsByRequirement())),
    )));
    sort($levels);

    expect($levels)->toBe([
        TEST_LEVEL_GATE_CONTRATO,
        TEST_LEVEL_GATE_E2E,
        TEST_LEVEL_GATE_FEATURE,
        TEST_LEVEL_GATE_INTEGRACION,
        TEST_LEVEL_GATE_UNITARIA,
    ]);
})->group('RQ-14');

/**
 * La linea donde el escaner situa una prueba: la de su `->group(`, la primera
 * a partir de su `it('…'`. Asi el caso de abajo no se ata a numeros de linea
 * que cambian con cada edicion del fichero que mira. Sin nombre, la linea 1.
 */
function testLevelGateTagLineOf(string $relative, string $name): int
{
    // Sin nombre no se lee nada: los ficheros de Playwright y k6 no estan
    // montados en el contenedor de PHP.
    $lines = $name === '' ? [] : explode("\n", (string) file_get_contents(\dirname(__DIR__, 3).'/'.$relative));
    $declared = array_key_first(array_filter($lines, static fn (string $line): bool => $name !== '' && str_contains($line, "it('".$name."'")));
    $tags = array_filter(
        $lines,
        static fn (string $line, int $index): bool => $declared !== null && $index >= $declared && str_contains($line, '->group('),
        ARRAY_FILTER_USE_BOTH,
    );

    return $tags === [] ? 1 : array_key_first($tags) + 1;
}

it('da a cada prueba el nivel de su carpeta, y contrato a la de feature que valida contra openapi.yaml', function (string $tool, string $file, string $name, array $levels): void {
    // R1-QA-02: contarlo solo por la carpeta `tests/Contract` daba 44 huecos
    // falsos. Las rutas son relativas al padre de `backend/`, como las da el
    // escaner: `backend/tests/…` en la CI y `html/tests/…` en el contenedor.
    $relative = str_starts_with($file, 'tests/') ? basename(\dirname(__DIR__, 2)).'/'.$file : $file;
    $line = testLevelGateTagLineOf($relative, $name);

    expect(testLevelGateLevelsOf(new TaggedTest($tool, $relative, $line, 'x', ['RF-AT-01'])))->toBe($levels);
})->with([
    'unitaria' => ['pest', 'tests/Unit/Attendance/Domain/MidnightShiftTest.php', '', [TEST_LEVEL_GATE_UNITARIA]],
    'integracion' => ['pest', 'tests/Integration/Reporting/PeriodReportVolumeTest.php', '', [TEST_LEVEL_GATE_INTEGRACION]],
    'feature que valida en la propia prueba' => [
        'pest',
        'tests/Feature/Identity/CredentialEndpointsTest.php',
        'emite una credencial y la deja pendiente de imprimir, sin acuñar ningun QR',
        [TEST_LEVEL_GATE_FEATURE, TEST_LEVEL_GATE_CONTRATO],
    ],
    'feature que valida en una funcion del fichero' => [
        'pest',
        'tests/Feature/Attendance/ClockSkewIncidentTest.php',
        'registra el fichaje del quiosco desviado y abre la incidencia de la noche',
        [TEST_LEVEL_GATE_FEATURE, TEST_LEVEL_GATE_CONTRATO],
    ],
    // El caso que la concesion por fichero daba mal: el fichero valida en otras
    // pruebas, esta no.
    'feature sin contrato en un fichero que si valida' => [
        'pest',
        'tests/Feature/Identity/CredentialEndpointsTest.php',
        'una credencial impresa si la resuelve el quiosco',
        [TEST_LEVEL_GATE_FEATURE],
    ],
    'feature sin contrato' => ['pest', 'tests/Feature/Attendance/AnomalyMetricsTest.php', '', [TEST_LEVEL_GATE_FEATURE]],
    'arquitectura, que no es ningun nivel del §9.5' => ['pest', 'tests/Architecture/DomainPurityTest.php', '', []],
    'playwright' => ['playwright', 'frontend-kiosk/tests/e2e/scan.spec.ts', '', [TEST_LEVEL_GATE_E2E]],
    'k6' => ['k6', 'load-tests/k6/scan-peak.js', '', []],
])->group('RQ-14');
