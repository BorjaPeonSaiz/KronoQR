<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Contract\Support\ContractSweep;
use Tests\Contract\Support\DependencyOutage;
use Tests\Support\Http\Api;

/*
 * **EL `503` DE UNA DEPENDENCIA CAIDA, VALIDADO EN CADA OPERACION QUE LO
 * DECLARA** (Q3; ADR-059, RNF-D-03, RQ-06).
 *
 * El contrato declara `503` en mas de cien operaciones: casi todas con la
 * respuesta compartida de dependencia caida, y las de PDF, la sonda de
 * disponibilidad o el portal con la suya. Esta prueba tira **PostgreSQL y
 * Redis a la vez**, de verdad (`DependencyOutage`), y pide cada operacion:
 *
 * - las rutas con sesion, con un token que no existe: comprobarlo es su
 *   primera consulta, y ahi cae PostgreSQL;
 * - las puertas publicas, sin credenciales: su limitador, que va antes de
 *   validar el cuerpo, cae con Redis.
 *
 * Las dos a la vez porque es lo que da a cada operacion su primer `503` sin
 * prepararle datos, y porque lo que se mide aqui es la **forma** de ese `503`
 * en cada ruta. Lo que hace cada familia con cada dependencia por separado —el
 * fichaje sin Redis, el limitador que falla abierto, el rastro sin el mensaje
 * de la excepcion— lo prueban `DependencyUnavailableTest`,
 * `ScanWithoutRedisTest` y `ScanWithDatabaseCircuitOpenTest`.
 *
 * **Sin `RefreshDatabase`**: con la base tirada, la transaccion de la prueba no
 * se podria revertir. No hace falta ningun dato y no se escribe nada.
 */

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

afterEach(function (): void {
    DependencyOutage::restore();
});

it('responde 503 con la forma del contrato con PostgreSQL y Redis caidos, en cada operacion que lo declara', function (): void {
    DependencyOutage::takeDown('postgres');
    DependencyOutage::takeDown('redis');

    $discrepancias = ContractSweep::discrepancies(503, static fn (string $operation) => ContractSweep::send(
        ContractSweep::clientWithAnyToken($operation),
        $operation,
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14', 'RNF-D-03');

it('responde 503 con la forma del contrato al acceso al portal con PostgreSQL caido', function (): void {
    // El barrido lo aparta: valida el cuerpo antes de tocar la base y su
    // limitador falla abierto sin Redis. Con un acceso bien formado, la primera
    // consulta —buscar el codigo— es la que cae.
    DependencyOutage::takeDown('postgres');

    $respuesta = Api::guest()->post('/api/v1/me/login', ['employee_code' => 'E-0001', 'pin' => '552190']);

    $respuesta->assertValidResponse(503);
})->group('RQ-06', 'RNF-D-03', 'RF-ID-06');

it('deja la base de datos en su sitio al terminar, para la prueba siguiente', function (): void {
    // El control de `DependencyOutage::restore()`: si la base se quedara en el
    // puerto muerto, la siguiente prueba del proceso fallaria por una causa que
    // no es suya.
    DependencyOutage::takeDown('postgres');

    DependencyOutage::restore();

    expect(DB::select('select 1 as uno')[0]->uno)->toBe(1);
})->group('RQ-06');
