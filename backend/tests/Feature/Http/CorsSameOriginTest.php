<?php

declare(strict_types=1);

use App\Support\Http\ApplicationOrigin;
use Tests\Support\Http\Api;

/*
 * CORS de la API: solo el origen propio, que sale de `APP_URL` (RS-09, hallazgo
 * R4-SC-08 de la 2.2.0, segundo aviso del DAST).
 *
 * Antes no habia `config/cors.php` y Laravel aplicaba el del framework:
 * `Access-Control-Allow-Origin: *` en toda respuesta de `/api/v1/*`. Ningun
 * llamador legitimo es de otro origen —las tres SPA se sirven desde el host de
 * la API—, asi que lo que estas pruebas fijan es:
 *
 *   - El origen de `APP_URL` recibe su propio origen, exacto, nunca `*`.
 *   - Un origen ajeno no recibe NADA, ni `*` ni el origen propio.
 *   - El preflight del origen propio responde `204` con las cabeceras que usan
 *     las SPA, entre ellas `Idempotency-Key` (regla dura 8).
 *   - Sin `Origin` —Prometheus, blackbox, `doctor.sh`— la respuesta es la de
 *     siempre y no lleva cabeceras de CORS.
 *
 * El origen de prueba no es el de `.env`: los patrones se calculan con
 * `ApplicationOrigin`, la misma clase que usa `config/cors.php`, a partir de una
 * `APP_URL` concreta; el resto de la configuracion es la que cargo el arranque.
 *
 * Sin base de datos: la sonda de vida no la toca y el preflight no llega a
 * ninguna ruta.
 */

const CORS_SAME_ORIGIN_APP_ORIGIN = 'https://fichaje.hotel.test';

beforeEach(function (): void {
    config(['cors.allowed_origins_patterns' => ApplicationOrigin::corsPatternsFor(CORS_SAME_ORIGIN_APP_ORIGIN.'/')]);
});

it('devuelve el origen propio, exacto, cuando la peticion viene de APP_URL', function (): void {
    $response = Api::guest()->withHeaders(['Origin' => CORS_SAME_ORIGIN_APP_ORIGIN])->get('/api/v1/health');

    $response->assertOk();
    $response->assertHeader('Access-Control-Allow-Origin', CORS_SAME_ORIGIN_APP_ORIGIN);
    expect((string) $response->headers->get('Vary'))->toContain('Origin');
})->group('RS-09');

it('no da permiso a un origen ajeno: ni comodin ni el origen propio', function (string $origin): void {
    $response = Api::guest()->withHeaders(['Origin' => $origin])->get('/api/v1/health');

    $response->assertOk();
    $response->assertHeaderMissing('Access-Control-Allow-Origin');
})->with([
    'otro dominio' => 'https://evil.example',
    'subdominio del propio' => 'https://evil.fichaje.hotel.test',
    'el propio como prefijo' => 'https://fichaje.hotel.test.evil.example',
    'otro esquema' => 'http://fichaje.hotel.test',
    'otro puerto' => 'https://fichaje.hotel.test:8443',
    'null de un iframe aislado' => 'null',
])->group('RS-09');

it('responde al preflight del origen propio con 204 y las cabeceras de las SPA', function (): void {
    $response = Api::guest()->withHeaders([
        'Origin' => CORS_SAME_ORIGIN_APP_ORIGIN,
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization, idempotency-key, content-type, accept',
    ])->call('OPTIONS', '/api/v1/scan');

    $response->assertNoContent();
    $response->assertHeader('Access-Control-Allow-Origin', CORS_SAME_ORIGIN_APP_ORIGIN);

    $methods = array_map(trim(...), explode(',', (string) $response->headers->get('Access-Control-Allow-Methods')));
    expect($methods)->toContain('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

    $headers = array_map(
        static fn (string $header): string => strtolower(trim($header)),
        explode(',', (string) $response->headers->get('Access-Control-Allow-Headers')),
    );
    expect($headers)->toContain('authorization', 'idempotency-key', 'content-type', 'accept');
})->group('RS-09');

it('no da permiso en el preflight de un origen ajeno', function (): void {
    $response = Api::guest()->withHeaders([
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization',
    ])->call('OPTIONS', '/api/v1/scan');

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
})->group('RS-09');

it('sin Origin la respuesta no cambia y no lleva cabeceras de CORS', function (): void {
    $response = Api::guest()->get('/api/v1/health');

    $response->assertOk();
    $response->assertHeaderMissing('Access-Control-Allow-Origin');
    $response->assertHeaderMissing('Access-Control-Allow-Credentials');
})->group('RS-09');

it('deriva el origen de APP_URL como lo escribe el navegador', function (mixed $appUrl, string $origin, bool $allowed): void {
    $patterns = ApplicationOrigin::corsPatternsFor($appUrl);

    $matches = array_filter($patterns, static fn (string $pattern): bool => preg_match($pattern, $origin) === 1);

    expect($matches !== [])->toBe($allowed);
})->with([
    'con barra final' => ['https://fichaje.hotel.test/', 'https://fichaje.hotel.test', true],
    'con ruta' => ['https://fichaje.hotel.test/kiosk', 'https://fichaje.hotel.test', true],
    'puerto de serie de https' => ['https://fichaje.hotel.test:443', 'https://fichaje.hotel.test', true],
    'puerto de serie de http' => ['http://10.0.0.5:80', 'http://10.0.0.5', true],
    'puerto propio' => ['https://fichaje.hotel.test:8443', 'https://fichaje.hotel.test:8443', true],
    'puerto propio sin el puerto' => ['https://fichaje.hotel.test:8443', 'https://fichaje.hotel.test', false],
    'mayusculas en el host' => ['https://Fichaje.Hotel.TEST', 'https://fichaje.hotel.test', true],
    'el punto no es comodin' => ['https://fichaje.hotel.test', 'https://fichajexhotel.test', false],
    'APP_URL vacia' => ['', 'https://localhost', false],
    'APP_URL sin esquema' => ['fichaje.hotel.test', 'https://fichaje.hotel.test', false],
    'APP_URL de otro esquema' => ['ftp://fichaje.hotel.test', 'ftp://fichaje.hotel.test', false],
    'APP_URL ausente' => [null, 'https://localhost', false],
])->group('RS-09');

it('no publica ningun comodin ni credenciales entre origenes', function (): void {
    // El fichero tal cual lo carga el arranque, sin el patron de prueba de arriba.
    /** @var array<string, mixed> $config */
    $config = require base_path('config/cors.php');

    expect($config['allowed_origins'])->toBe([])
        ->and($config['allowed_origins_patterns'])->toBe(ApplicationOrigin::corsPatternsFor(config('app.url')))
        ->and(ApplicationOrigin::corsPatternsFor('https://localhost'))->toBe(['#\Ahttps\://localhost\z#'])
        ->and($config['paths'])->toBe(['api/*'])
        ->and($config['supports_credentials'])->toBeFalse();
})->group('RS-09');
