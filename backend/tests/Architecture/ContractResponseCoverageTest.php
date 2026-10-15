<?php

declare(strict_types=1);

use Tests\Architecture\Support\ContractCoverage;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * Toda operacion del contrato tiene al menos una respuesta validada contra
 * `openapi.yaml` en la suite (RQ-06, RQ-14; Q3 de la verificacion de la 2.2.0).
 *
 * ## Por que hace falta
 *
 * Spectator solo comprueba lo que se le pide. Un endpoint cuyas pruebas afirman
 * `assertStatus(200)` y nunca `assertValidResponse()` puede devolver campos de
 * mas, un tipo de contenido que el contrato no declara o un codigo que no
 * existe, y la suite sale en verde. La verificacion final de la 2.2.0 conto diez
 * operaciones asi —ocho descargas, `deliver` y la revocacion de un acceso de
 * soporte— y ninguna puerta que impidiera la undecima. Esta es esa puerta.
 *
 * ## Como lo comprueba
 *
 * Leyendo las pruebas de `tests/Feature` y `tests/Contract` como texto, con las
 * reglas de {@see ContractCoverage}: cada `assertValidResponse` se atribuye a la
 * ultima peticion que la precede en su `it(...)` —o en la funcion ayudante del
 * fichero que la hace—, como hace Spectator en ejecucion, y solo cuenta si el
 * fichero ha cargado el contrato con `Spectator::using(...)`.
 *
 * Una operacion que falte se arregla con una prueba que valide **una** de sus
 * respuestas, aunque sea la de error: para una descarga binaria, el `404` o el
 * `422` en JSON comprueban de verdad la forma, y el `200` comprueba al menos el
 * codigo y el tipo de contenido. No hay lista de excepciones: si algun dia hace
 * falta una, se discute en revision, con su motivo, y no se añade aqui sin mas.
 */

const CONTRACT_RESPONSE_COVERAGE_OPS = [
    'GET /api/v1/employees',
    'GET /api/v1/employees/{uuid}',
    'POST /api/v1/employees',
    'POST /api/v1/employees/import',
    'POST /api/v1/scan',
];

/**
 * @return list<string>
 */
function contractResponseCoverageOperations(): array
{
    return ContractCoverage::operations(Repo::file('docs/api/openapi.yaml'));
}

/**
 * @return list<string>
 */
function contractResponseCoverageTestFiles(): array
{
    $tests = \dirname(__DIR__);

    return array_values(array_filter(
        [...ModuleTree::phpFilesUnder($tests.'/Feature'), ...ModuleTree::phpFilesUnder($tests.'/Contract')],
        static fn (string $file): bool => str_ends_with($file, 'Test.php'),
    ));
}

/**
 * Las operaciones que valida un fichero de pruebas escrito para el caso.
 *
 * @return list<string>
 */
function contractResponseCoverageOf(string $body, bool $loadsContract = true): array
{
    $source = "<?php\n\n"
        .($loadsContract ? "beforeEach(function (): void {\n    Spectator::using('openapi.yaml');\n});\n\n" : '')
        .$body;

    $validated = ContractCoverage::validatedIn($source, CONTRACT_RESPONSE_COVERAGE_OPS);
    sort($validated);

    return $validated;
}

it('valida al menos una respuesta de cada operacion del contrato', function (): void {
    $operations = contractResponseCoverageOperations();
    $validated = ContractCoverage::validated($operations, contractResponseCoverageTestFiles());

    expect(array_values(array_diff($operations, array_keys($validated))))->toBe(
        [],
        'Operacion(es) del contrato sin ninguna respuesta validada con assertValidResponse(): anade una prueba '
        .'que valide al menos una de sus respuestas (Spectator::using en el fichero, la peticion con su ruta '
        .'literal y assertValidResponse detras). Ver el docblock de este fichero.',
    );
})->group('RQ-06', 'RQ-14');

it('lee el contrato entero y todas las pruebas, y no pasa en verde por no ver nada', function (): void {
    // Si la forma del YAML o el arbol de pruebas cambiaran y el lector dejara de
    // ver operaciones o ficheros, la comprobacion de arriba saldria en verde sin
    // mirar nada. Las cifras de hoy son 100 operaciones y mas de 200 ficheros.
    expect(count(contractResponseCoverageOperations()))->toBeGreaterThan(90)
        ->and(contractResponseCoverageOperations())->toContain('POST /api/v1/scan', 'DELETE /api/v1/support/grants/{uuid}')
        ->and(count(contractResponseCoverageTestFiles()))->toBeGreaterThan(200);
})->group('RQ-06', 'RQ-14');

it('atribuye la validacion a la peticion encadenada, con la ruta literal o interpolada', function (): void {
    expect(contractResponseCoverageOf(<<<'PHP'
        it('a', function (): void {
            Api::as($t)->get('/api/v1/employees')->assertValidResponse(200);
            Api::as($t)->get("/api/v1/employees/{$uuid}")->assertStatus(200)->assertValidResponse(200);
            Api::as($t)->post('/api/v1/employees/'.$uuid.'/nada')->assertValidResponse(404);
        });
        PHP))->toBe(['GET /api/v1/employees', 'GET /api/v1/employees/{uuid}']);
})->group('RQ-06');

it('atribuye la validacion a la ULTIMA peticion, que es la que valida Spectator', function (): void {
    // `OutOfOrderScanTest` valida una respuesta guardada despues de la peticion
    // siguiente: Spectator mira la ultima, y aqui tambien.
    expect(contractResponseCoverageOf(<<<'PHP'
        it('a', function (): void {
            $primera = Api::as($t)->post('/api/v1/employees', []);
            $segunda = Api::as($t)->get('/api/v1/employees');
            $primera->assertValidResponse(201);
        });
        PHP))->toBe(['GET /api/v1/employees']);
})->group('RQ-06');

it('cuenta la peticion de una funcion ayudante del mismo fichero', function (): void {
    expect(contractResponseCoverageOf(<<<'PHP'
        function fichar(): TestResponse
        {
            return Api::as('t')->post('/api/v1/scan', []);
        }

        it('a', function (): void {
            fichar()->assertValidResponse(200);
        });
        PHP))->toBe(['POST /api/v1/scan']);
})->group('RQ-06');

it('no cuenta nada en un fichero que no carga el contrato', function (): void {
    // Sin `Spectator::using()` el middleware no valida y `assertValidResponse`
    // pasa sobre cualquier respuesta.
    $body = <<<'PHP'
        it('a', function (): void {
            Api::as($t)->get('/api/v1/employees')->assertValidResponse(200);
        });
        PHP;

    expect(contractResponseCoverageOf($body, loadsContract: false))->toBe([]);

    // Pero si lo carga la propia prueba, o un ayudante al que llama, si cuenta.
    expect(contractResponseCoverageOf(<<<'PHP'
        function escenario(): void
        {
            Spectator::using('openapi.yaml');
        }

        it('a', function (): void {
            escenario();
            Api::as($t)->get('/api/v1/employees')->assertValidResponse(200);
        });

        it('b', function (): void {
            Api::as($t)->post('/api/v1/scan', [])->assertValidResponse(200);
        });
        PHP, loadsContract: false))->toBe(['GET /api/v1/employees']);
})->group('RQ-06');

it('no cuenta una peticion cuya ruta es una variable entera, ni una validacion sin peticion delante', function (): void {
    expect(contractResponseCoverageOf(<<<'PHP'
        it('a', function (): void {
            Api::as($t)->get($ruta)->assertValidResponse(200);
        });

        it('b', function (): void {
            $respuesta->assertValidResponse(200);
        });
        PHP))->toBe([]);
})->group('RQ-06');

it('corta la atribucion en una peticion a una ruta variable, y no en un get que no es una peticion', function (): void {
    // `->get($enlace)` desde `Api::` es una peticion aunque no se pueda leer su
    // ruta, y lo que se valide despues es suyo, no de la anterior. En cambio
    // `$respuesta->headers->get(...)` no es una peticion y no corta nada.
    expect(contractResponseCoverageOf(<<<'PHP'
        it('a', function (): void {
            $respuesta = Api::as($t)->get('/api/v1/employees');
            $etag = $respuesta->headers->get('ETag');
            $respuesta->assertValidResponse(200);
        });

        it('b', function (): void {
            Api::as($t)->post('/api/v1/scan', []);
            Api::guest()->withHeaders(['If-None-Match' => $etag])->get($enlace)->assertValidResponse(410);
        });
        PHP))->toBe(['GET /api/v1/employees']);
})->group('RQ-06');

it('resuelve la ruta con texto literal antes que la del parametro, como el router', function (): void {
    expect(ContractCoverage::match('POST', '/api/v1/employees/import', CONTRACT_RESPONSE_COVERAGE_OPS))
        ->toBe('POST /api/v1/employees/import')
        ->and(ContractCoverage::match('GET', '/api/v1/employees/x?expand=1', CONTRACT_RESPONSE_COVERAGE_OPS))
        ->toBe('GET /api/v1/employees/{uuid}')
        ->and(ContractCoverage::match('DELETE', '/api/v1/employees/x', CONTRACT_RESPONSE_COVERAGE_OPS))
        ->toBeNull();
})->group('RQ-06');
