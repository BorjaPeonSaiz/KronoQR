<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as Redis;
use Spectator\Spectator;
use Tests\Support\Health\UnavailableRedis;
use Tests\Support\Http\Api;

/*
 * `GET /api/v1/ready` — la sonda de disponibilidad (doc 01 Anexo B).
 *
 * Feature **y contrato a la vez**: las dos respuestas —el `200` y el `503` en
 * `application/problem+json`— se validan contra `docs/api/openapi.yaml`.
 *
 * **Las dependencias caidas se simulan, no se apagan.** Ninguna prueba de este
 * fichero para PostgreSQL ni Redis: la conexion por omision se apunta a un
 * nombre inexistente y Redis se sustituye por un doble que se niega a conectar.
 * Apagar servicios de verdad dejaria la suite dependiendo del orden de ejecucion
 * y del estado del entorno de quien la lanza.
 *
 * El caso sano si usa las dependencias reales, y tiene que hacerlo: una sonda de
 * disponibilidad que diera verde contra un doble no comprobaria nada.
 */

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

it('confirma que la instancia acepta trafico cuando las dependencias responden', function (): void {
    Api::guest()->get('/api/v1/ready')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'ready');
})->group('RQ-06');

it('no exige autenticacion, como toda sonda del orquestador', function (): void {
    // El contrato la declara `security: []`. Quien la consulta es el propio
    // despliegue, antes de que exista sesion alguna.
    Api::guest()->get('/api/v1/ready')->assertOk();
})->group('RQ-06');

it('responde 503 en problem+json cuando la base de datos no responde', function (): void {
    config(['database.default' => 'una-conexion-que-no-existe']);

    Api::guest()->get('/api/v1/ready')
        ->assertValidResponse(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-ready')
        ->assertJsonPath('status', 503);
})->group('RQ-06');

it('responde 503 cuando Redis no responde', function (): void {
    app()->instance(Redis::class, new UnavailableRedis);

    Api::guest()->get('/api/v1/ready')
        ->assertValidResponse(503)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-ready');
})->group('RQ-06');

it('no dice que dependencia ha fallado', function (): void {
    /*
     * La decision de seguridad de este endpoint, y la unica que no se ve mirando
     * un codigo de estado: **el cuerpo es identico con la base de datos caida y
     * con Redis caido**. Es publico y sin autenticacion, asi que enumerar los
     * servicios que no responden seria repartir un mapa de la instalacion a
     * cualquiera que sepa la URL. El diagnostico por componente existe y es otra
     * cosa: RF-PD-09 y RF-PD-13, acciones autenticadas del administrador.
     *
     * Se comparan los dos cuerpos entre si, y no cada uno contra una lista de
     * palabras prohibidas: mientras sean iguales, no hay nada que distinguir.
     */
    $conexionReal = config()->string('database.default');

    config(['database.default' => 'una-conexion-que-no-existe']);
    $sinBaseDeDatos = (string) Api::guest()->get('/api/v1/ready')->getContent();

    config(['database.default' => $conexionReal]);
    app()->instance(Redis::class, new UnavailableRedis);
    $sinRedis = (string) Api::guest()->get('/api/v1/ready')->getContent();

    expect($sinBaseDeDatos)->toBe($sinRedis);

    // Y por si algun dia los dos cuerpos coincidieran en decir de mas: ninguna
    // pieza de la instalacion se nombra.
    foreach (['database', 'redis', 'pgsql', 'postgres', 'sql', '6379', '5432'] as $pista) {
        expect(strtolower($sinBaseDeDatos))->not->toContain($pista);
    }
});
