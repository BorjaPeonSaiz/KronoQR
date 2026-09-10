<?php

declare(strict_types=1);

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\Support\Http\Api;

/*
 * **NINGUN PROXY ES DE CONFIANZA MIENTRAS NADIE LO DECLARE** (RS-02, RS-09,
 * RS-12, regla dura 13, tarea 3.1).
 *
 * ## El defecto que estas pruebas cierran
 *
 * `Illuminate\Http\Middleware\TrustProxies`, sin proxies declarados, confia en
 * `X-Forwarded-For` **cuando el `Host` de la peticion termina en
 * `.on-forge.com` o `.on-vapor.com`**: es una comodidad para las plataformas de
 * Laravel. `bootstrap/app.php` no declaraba proxies y el Nginx del producto es
 * `server_name _`, que captura cualquier `Host`. Con eso, dos cabeceras que
 * escribe el cliente bastaban para decidir que devuelve `$request->ip()`.
 *
 * Y de `$request->ip()` dependen tres cosas del producto:
 *
 *   · el `403` de `GET /metrics` (RS-09), que es una guarda de RED;
 *   · la IP que consta en `audit_log` para cada acto con valor legal (regla
 *     dura 6): un asiento con una direccion inventada es un asiento que miente
 *     en el unico campo que dice desde donde se hizo algo;
 *   · los limites por IP de los intentos de autenticacion (RS-12), que se
 *     evaden cambiando la cabecera en cada intento.
 *
 * ## Que se afirma
 *
 * Que con `TRUSTED_PROXIES` vacia —el valor de serie— `$request->ip()` es
 * `REMOTE_ADDR`, **con el `Host` que sea**, y que `/metrics` sigue cerrado desde
 * fuera del CIDR aunque la peticion traiga una `X-Forwarded-For` de dentro.
 *
 * ## Sin base de datos
 *
 * No hace falta: lo que se prueba es como resuelve la aplicacion la direccion de
 * origen, y eso ocurre en el middleware global, antes de cualquier consulta.
 */

/** El `Host` con el que se alcanzaba la heuristica del framework. */
const HOST_DE_LA_HEURISTICA = 'kronoqr.on-forge.com';

const RUTA_QUE_DICE_LA_IP = '/api/v1/__proxies/ip';

beforeEach(function (): void {
    config([
        'observability.metrics.allow_cidr' => '10.91.0.0/24',
        // Vacia: el valor de serie del producto y el de la inmensa mayoria de las
        // instalaciones, donde Nginx habla FastCGI y REMOTE_ADDR ya es el cliente.
        'observability.trusted_proxies' => '',
    ]);

    Route::middleware('api')->get(
        RUTA_QUE_DICE_LA_IP,
        static fn (): array => ['ip' => request()->ip()],
    )->name('proxies.ip');
});

it('responde 403 en /metrics desde fuera del CIDR aunque el Host sea el de la heuristica', function (): void {
    // La prueba literal del defecto. Sin la correccion, la cabecera de abajo
    // convertia a este cliente en `10.91.0.5` y `/metrics` respondia 200 con la
    // operacion del hotel entera.
    Api::guest()
        ->fromIp('203.0.113.7')
        ->withHeaders([
            'Host' => HOST_DE_LA_HEURISTICA,
            'X-Forwarded-For' => '10.91.0.5',
        ])
        ->get('/metrics')
        ->assertForbidden();
})->group('RS-09');

it('tampoco abre /metrics con X-Forwarded-For desde un Host normal', function (): void {
    Api::guest()
        ->fromIp('203.0.113.7')
        ->withHeaders(['X-Forwarded-For' => '10.91.0.5'])
        ->get('/metrics')
        ->assertForbidden();
})->group('RS-09');

it('la IP de la peticion es REMOTE_ADDR y no lo que diga X-Forwarded-For', function (): void {
    /*
     * La misma garantia vista desde la aplicacion, y la que sostiene los otros
     * dos efectos: la IP de `audit_log` y la clave de los limites de RS-12. Se
     * envia el `Host` de la heuristica **a proposito**: es la unica forma de que
     * esta prueba falle si alguien devuelve el `TrustProxies` del framework.
     */
    $response = Api::guest()
        ->fromIp('198.51.100.23')
        ->withHeaders([
            'Host' => HOST_DE_LA_HEURISTICA,
            'X-Forwarded-For' => '10.91.0.5, 172.16.0.1',
        ])
        ->get(RUTA_QUE_DICE_LA_IP);

    $response->assertOk();

    expect($response->json('ip'))->toBe('198.51.100.23');
})->group('RS-02', 'RS-12');

it('cuando el cliente declara su balanceador, y solo entonces, se lee la cabecera', function (): void {
    // La otra mitad: la variable existe porque una instalacion puede tener otro
    // balanceador por delante de Nginx. Declararlo es una decision del cliente,
    // escrita en su `.env`, no algo que active un nombre de dominio.
    config(['observability.trusted_proxies' => '198.51.100.23']);

    $response = Api::guest()
        ->fromIp('198.51.100.23')
        ->withHeaders(['X-Forwarded-For' => '10.91.0.5'])
        ->get(RUTA_QUE_DICE_LA_IP);

    $response->assertOk();

    expect($response->json('ip'))->toBe('10.91.0.5');
})->group('RS-02');

it('la lista de proxies sale de la configuracion y admite coma o espacio', function (): void {
    config(['observability.trusted_proxies' => '10.0.0.1, 10.0.0.2   10.0.0.3']);

    expect(TrustProxies::proxies())->toBe(['10.0.0.1', '10.0.0.2', '10.0.0.3']);

    config(['observability.trusted_proxies' => '   ']);

    // Solo espacios es lista vacia, no un proxy llamado «espacio».
    expect(TrustProxies::proxies())->toBe([]);
})->group('RS-02');

it('el middleware registrado es el del producto, no el del framework', function (): void {
    /*
     * La garantia estructural. Las pruebas de arriba comprueban el efecto, pero
     * el efecto tambien seria correcto si alguien retirara el middleware del
     * todo — y entonces se perderia `TRUSTED_PROXIES` para quien lo necesita.
     * Aqui se fija que la sustitucion esta hecha y sigue hecha.
     */
    $kernel = app()->make(HttpKernel::class);

    $middleware = $kernel->getGlobalMiddleware();

    expect($middleware)->toContain(TrustProxies::class)
        ->and($middleware)->not->toContain(FrameworkTrustProxies::class);
})->group('RS-02');
