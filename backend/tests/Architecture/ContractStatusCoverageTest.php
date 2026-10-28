<?php

declare(strict_types=1);

use Tests\Architecture\Support\ContractCoverage;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;
use Tests\Contract\Support\ContractSweep;

/*
 * Todo codigo de respuesta que declara una operacion del contrato tiene su
 * respuesta validada contra `openapi.yaml` en la suite (Q3 de la verificacion
 * de la 2.1.0 y de la 2.2.1; RQ-06, RQ-14).
 *
 * ## Por que no basta con `ContractResponseCoverageTest`
 *
 * Aquella exige **una** respuesta validada por operacion. Una operacion con
 * nueve codigos declarados quedaba cubierta validando su `200`, y los otros
 * ocho podian llevar campos que el contrato no tiene, otro `type` o un esquema
 * que el cliente generado no sabe leer: la verificacion de la 2.2.1 conto 31
 * operaciones en las que solo se validaba la de exito. Esta puerta trabaja con
 * pares (operacion, codigo).
 *
 * ## Que cuenta como cubierto
 *
 * Un par esta cubierto si lo valida:
 *
 * - **una prueba explicita**: `assertValidResponse(404)` con el codigo
 *   escrito, atribuido a la operacion por las reglas de
 *   {@see ContractCoverage} —la ultima peticion antes de la validacion, como
 *   Spectator, en un fichero que carga el contrato—;
 * - **o un barrido**: `ContractSweep::discrepancies(N, ...)` en un fichero que
 *   carga el contrato, que cubre todas las operaciones que declaran `N` menos
 *   las que el barrido aparta con su motivo ({@see ContractSweep::sweptPairs()}).
 *
 * Una validacion **sin codigo literal** (`assertValidResponse()`, o con la
 * variable de un dataset) no cubre ningun par: leyendo la prueba no se sabe que
 * respuesta valido. Sigue contando para la operacion en la otra puerta.
 *
 * ## Lo que no esta cubierto, con su motivo
 *
 * {@see CONTRACT_STATUS_COVERAGE_KNOWN_DISCREPANCIES} son pares que el contrato
 * declara y la implementacion **no produce con esa forma**: discrepancias entre
 * los dos, anotadas para resolverse en el contrato o en el codigo, no aqui. No
 * son pares que cueste probar: los que costaba tienen ya su prueba. Cada una caduca sola: si el par llega a estar cubierto, o el contrato
 * deja de declararlo, la prueba de abajo obliga a quitarla.
 */

/**
 * Pares declarados que la implementacion no produce con la forma del contrato,
 * con el motivo y el arreglo que piden.
 *
 * @var array<string, string>
 */
const CONTRACT_STATUS_COVERAGE_KNOWN_DISCREPANCIES = [
    'POST /api/v1/client-errors 400' => 'Declara 400 InvalidRequest (ValidationProblem, con errors). ReportClientErrorsRequest valida con 422 y no emite invalid-request; el unico 400 posible es el bad-request de un JSON mal formado (D6), sin errors, que el esquema rechaza.',
    'POST /api/v1/kiosk/pair/confirm 400' => 'Declara 400 InvalidRequest (ValidationProblem, con errors). ConfirmPairingRequest valida con 422 y no emite invalid-request; el unico 400 posible es el bad-request de un JSON mal formado (D6), sin errors, que el esquema rechaza.',
    'GET /api/v1/reports/exports/{uuid}/download 200' => 'Declara text/csv para el CSV. La descarga no fija el tipo y BinaryFileResponse lo adivina por el contenido: un CSV con BOM y punto y coma, el del producto, sale como text/plain. Arreglo en ReportExportDownloadController (tipo segun el formato); hallazgo de la tarea 22.3.',
];

/**
 * Cada par `METODO /ruta CODIGO` que declara el contrato.
 *
 * @return list<string>
 */
function contractStatusCoverageDeclared(): array
{
    static $pairs = null;

    if ($pairs !== null) {
        return $pairs;
    }

    $pairs = [];

    foreach (ContractCoverage::responses(Repo::file('docs/api/openapi.yaml')) as $operation => $codes) {
        $pairs = [...$pairs, ...array_map(static fn (int $code): string => $operation.' '.$code, $codes)];
    }

    return $pairs;
}

/**
 * @return list<string>
 */
function contractStatusCoverageTestFiles(): array
{
    $tests = \dirname(__DIR__);

    return array_values(array_filter(
        [...ModuleTree::phpFilesUnder($tests.'/Feature'), ...ModuleTree::phpFilesUnder($tests.'/Contract')],
        static fn (string $file): bool => str_ends_with($file, 'Test.php'),
    ));
}

/**
 * Los pares cubiertos por la suite: los validados con codigo literal y los de
 * los barridos.
 *
 * @return list<string>
 */
function contractStatusCoverageCovered(): array
{
    static $covered = null;

    if ($covered !== null) {
        return $covered;
    }

    $files = contractStatusCoverageTestFiles();
    $operations = array_keys(ContractCoverage::responses(Repo::file('docs/api/openapi.yaml')));
    $swept = array_merge(...array_map(
        static fn (string $file): array => ContractCoverage::sweptCodesIn((string) file_get_contents($file)),
        $files,
    ));

    return $covered = array_values(array_unique([
        ...array_keys(ContractCoverage::validatedPairs($operations, $files)),
        ...array_merge(...array_map(static fn (int $code): array => ContractSweep::sweptPairs($code), array_unique($swept))),
    ]));
}

/**
 * Los codigos que barre un fichero escrito para el caso.
 *
 * @return list<int>
 */
function contractStatusCoverageSweptIn(string $body, bool $loadsContract = true): array
{
    return ContractCoverage::sweptCodesIn(
        "<?php\n\n"
        .($loadsContract ? "beforeEach(function (): void {\n    Spectator::using('openapi.yaml');\n});\n\n" : '')
        .$body,
    );
}

it('valida cada codigo de respuesta que declara cada operacion del contrato', function (): void {
    $sinCubrir = array_values(array_diff(
        contractStatusCoverageDeclared(),
        contractStatusCoverageCovered(),
        array_keys(CONTRACT_STATUS_COVERAGE_KNOWN_DISCREPANCIES),
    ));

    expect($sinCubrir)->toBe(
        [],
        'Par(es) (operacion, codigo) del contrato sin ninguna respuesta validada: anade una prueba que provoque '
        .'ese codigo y lo valide con assertValidResponse(<codigo>) —el codigo escrito—, o, si es una respuesta '
        .'compartida, comprueba que su barrido de tests/Contract no la aparta. Ver el docblock de este fichero.',
    );
})->group('RQ-06', 'RQ-14');

it('no guarda como discrepancia conocida un par que ya esta cubierto o que el contrato ya no declara', function (): void {
    $caducados = array_values(array_filter(
        array_keys(CONTRACT_STATUS_COVERAGE_KNOWN_DISCREPANCIES),
        static fn (string $pair): bool => in_array($pair, contractStatusCoverageCovered(), true)
            || ! in_array($pair, contractStatusCoverageDeclared(), true),
    ));

    expect($caducados)->toBe([], 'Quita estos pares de CONTRACT_STATUS_COVERAGE_KNOWN_DISCREPANCIES: ya no son una discrepancia.');
})->group('RQ-06');

it('lee el contrato entero y todas las pruebas, y no pasa en verde por no ver nada', function (): void {
    // Las cifras del dia en que se escribio: 719 pares, ocho codigos barridos
    // y casi 300 ficheros de pruebas. Si el lector dejara de ver algo, la
    // comprobacion de arriba saldria en verde sin mirar.
    $barridos = array_values(array_unique(array_merge(...array_map(
        static fn (string $file): array => ContractCoverage::sweptCodesIn((string) file_get_contents($file)),
        contractStatusCoverageTestFiles(),
    ))));
    sort($barridos);

    expect(count(contractStatusCoverageDeclared()))->toBeGreaterThan(700)
        ->and($barridos)->toBe([400, 401, 403, 404, 415, 422, 429, 503])
        ->and(count(contractStatusCoverageTestFiles()))->toBeGreaterThan(280);
})->group('RQ-06', 'RQ-14');

it('cuenta el par con el codigo escrito, y no la validacion sin codigo', function (): void {
    $fuente = <<<'PHP'
        <?php

        beforeEach(function (): void {
            Spectator::using('openapi.yaml');
        });

        it('a', function (): void {
            Api::as($t)->get('/api/v1/employees')->assertValidResponse(200);
            Api::as($t)->get("/api/v1/employees/{$uuid}")->assertStatus(404)->assertValidResponse();
            Api::as($t)->post('/api/v1/employees', [])->assertValidResponse($estado);
            Api::as($t)->raw('POST', '/api/v1/scan', 'text/plain', 'x')->assertValidResponse(415);
        });
        PHP;

    $pares = ContractCoverage::validatedPairsIn($fuente, ['GET /api/v1/employees', 'GET /api/v1/employees/{uuid}', 'POST /api/v1/employees', 'POST /api/v1/scan']);
    sort($pares);

    expect($pares)->toBe(['GET /api/v1/employees 200', 'POST /api/v1/scan 415']);
})->group('RQ-06');

it('reconoce el barrido por su codigo literal, solo en un fichero que carga el contrato', function (): void {
    $barrido = <<<'PHP'
        it('a', function (): void {
            expect(ContractSweep::discrepancies(401, static fn (string $o) => ContractSweep::send(Api::guest(), $o)))->toBe([]);
        });

        it('b', function (): void {
            expect(ContractSweep::discrepancies($codigo, static fn (string $o) => ContractSweep::send(Api::guest(), $o)))->toBe([]);
        });
        PHP;

    expect(contractStatusCoverageSweptIn($barrido))->toBe([401])
        ->and(contractStatusCoverageSweptIn($barrido, loadsContract: false))->toBe([]);
})->group('RQ-06');

it('lee los codigos de cada operacion del contrato', function (): void {
    $respuestas = ContractCoverage::responses(Repo::file('docs/api/openapi.yaml'));

    expect($respuestas['DELETE /api/v1/support/grants/{uuid}'])->toBe([204, 401, 403, 404, 429, 503])
        ->and($respuestas['GET /api/v1/health'])->toBe([200]);
})->group('RQ-06');

it('da al barrido todas las operaciones que declaran el codigo salvo las que aparta, y cada apartada con su motivo', function (): void {
    $apartadas = ContractSweep::setAside(401);

    expect(ContractSweep::sweptPairs(401))->not->toContain('POST /api/v1/auth/login 401')
        ->and(ContractSweep::sweptPairs(401))->toContain('GET /api/v1/employees 401')
        ->and(count(ContractSweep::sweptPairs(401)) + count($apartadas))->toBe(count(ContractSweep::declaring(401)))
        ->and(array_filter($apartadas, static fn (string $motivo): bool => mb_strlen($motivo) < 40))->toBe([]);
})->group('RQ-06');
