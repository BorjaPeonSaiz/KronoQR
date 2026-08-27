<?php

declare(strict_types=1);

use App\Support\Version\DeployedVersion;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spectator\Spectator;
use Tests\Support\Health\UnavailableRedis;
use Tests\Support\Http\Api;

/*
 * `GET /api/v1/health` — la sonda de vida (doc 01 Anexo B, doc 02 §10.5).
 *
 * Feature **y contrato a la vez**: la respuesta se valida contra
 * `docs/api/openapi.yaml` con Spectator, porque el cliente TypeScript de los tres
 * frontends se genera de ahi y el `version` que sale de aqui tiene que casar con
 * el patron SemVer del esquema `Health`.
 *
 * **Sin base de datos y a proposito.** Este fichero no usa `RefreshDatabase`: si
 * lo necesitara, la sonda no estaria haciendo lo que promete.
 *
 * La promesa que se comprueba aqui, y que es toda la razon de que existan dos
 * sondas y no una: **una sonda de vida que toca dependencias reinicia el
 * contenedor de PHP cuando lo que esta caido es PostgreSQL**. Eso tira las
 * conexiones sanas, reinicia en bucle mientras dura la incidencia y apunta el
 * diagnostico al sitio equivocado.
 */

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

it('responde 200 con el estado y la version desplegada', function (): void {
    $response = Api::guest()->get('/api/v1/health');

    $response->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'ok');

    // Ademas del esquema, el patron explicito: `assertValidResponse` deja de
    // comprobarlo el dia que alguien afloje el contrato, y este es el campo cuya
    // forma decide si el endpoint cumple o no (doc 02 §10.5).
    expect($response->json('version'))
        ->toBeString()
        ->toMatch(DeployedVersion::SEMVER);
})->group('RQ-06');

it('publica exactamente la version que resuelve la configuracion', function (): void {
    // El controlador no puede tener su propia idea de la version: lee
    // `config('app.version')`, que es lo que resuelve DeployedVersion al arrancar.
    Api::guest()->get('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('version', config()->string('app.version'));
})->group('RQ-06');

it('no exige autenticacion, porque quien la consulta todavia no la tiene', function (): void {
    // El contrato la declara `security: []`. Un orquestador que arranca un
    // contenedor no tiene con que autenticarse, y una sonda que respondiera 401
    // marcaria la instancia como muerta para siempre.
    Api::guest()->get('/api/v1/health')->assertOk();
})->group('RQ-06');

it('no ejecuta ninguna consulta a la base de datos', function (): void {
    $queries = 0;

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    Api::guest()->get('/api/v1/health')->assertOk();

    expect($queries)->toBe(0);
});

it('sigue respondiendo 200 con PostgreSQL y Redis caidos', function (): void {
    // La prueba que da sentido a todas las demas de este fichero, y la unica que
    // se rompe si alguien anade «solo una comprobacion mas» al controlador.
    //
    // Las dos dependencias se tumban sin apagar nada: la conexion por omision
    // apunta a un nombre que no existe —cualquier uso de la base de datos
    // reventaria— y Redis se sustituye por un doble que se niega a conectar.
    config(['database.default' => 'una-conexion-que-no-existe']);
    app()->instance(Redis::class, new UnavailableRedis);

    Api::guest()->get('/api/v1/health')
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'ok');
})->group('RQ-06');

it('deja las dos sondas sin autenticacion y sin limitador de peticiones', function (string $name): void {
    /*
     * La mitad que no se ve desde una respuesta `200`.
     *
     * `auth:sanctum` esta fuera por el contrato (`security: []`). El limitador
     * esta fuera por algo menos evidente y mas grave: el `throttle` de Laravel
     * cuenta en la cache, que en produccion es Redis, asi que una sonda de VIDA
     * limitada dejaria de responder justo cuando Redis se cayera —y Docker
     * reiniciaria PHP por un fallo que no es suyo—. El techo de peticiones de
     * estas dos rutas lo pone Nginx (§7.2).
     *
     * Se mira el middleware EFECTIVO —con el grupo `api` expandido—, no el
     * declarado en la ruta: un `->throttleApi()` en `bootstrap/app.php` se lo
     * anadiria a las dos sin tocar `routes/api_v1.php`.
     */
    $route = Route::getRoutes()->getByName($name);

    if (! $route instanceof RoutingRoute) {
        throw new RuntimeException('No existe la ruta '.$name.'.');
    }

    $middleware = array_map(
        static fn (mixed $entry): string => is_string($entry) ? $entry : get_debug_type($entry),
        app(Router::class)->gatherRouteMiddleware($route),
    );

    foreach ($middleware as $entry) {
        expect($entry)->not->toContain('ThrottleRequests')
            ->and($entry)->not->toContain('Authenticate')
            ->and($entry)->not->toContain('CheckForAnyAbility')
            ->and($entry)->not->toContain('CheckAbilities');
    }
})->with(['health.live', 'health.ready']);
