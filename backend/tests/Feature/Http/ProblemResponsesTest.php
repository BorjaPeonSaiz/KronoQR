<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * LA FORMA DE LOS ERRORES (tarea 10.5; D6, V21-3-BE-3, F4a-3, F4a-4, F4a-5,
 * F4a-6).
 *
 * - Un JSON mal formado responde `400 bad-request` y no guarda nada; un cuerpo
 *   de otro tipo, `415`; las dos rutas de subida admiten `multipart`.
 * - `title` y `detail` en el idioma pedido, con `Content-Language`.
 * - Un `422` lleva siempre `errors`.
 * - Cada `503` con su `type` real e `instance`.
 */

uses(RefreshDatabase::class);

/**
 * Una peticion con el cuerpo crudo tal cual.
 *
 * @return TestResponse<Response>
 */
function problemResponsesRaw(string $method, string $uri, string $contentType, string $body, ?string $token = null): TestResponse
{
    $server = ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }

    return problemResponsesSend(Request::create($uri, $method, [], [], [], $server, $body));
}

/**
 * Por el kernel HTTP, como lo haria `call()` de la prueba.
 *
 * @return TestResponse<Response>
 */
function problemResponsesSend(Request $request): TestResponse
{
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return TestResponse::fromBaseResponse($response, $request);
}

it('responde 400 a un JSON mal formado y no cambia nada', function (): void {
    $site = WorkforceFixtures::site('Hotel del JSON roto');
    $antes = DB::table('sites')->where('id', $site)->value('name');
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    problemResponsesRaw('PATCH', '/api/v1/site', 'application/json', '{"name": "Hotel cambiado"', $token)
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'urn:kronoqr:problem:bad-request');

    expect(DB::table('sites')->where('id', $site)->value('name'))->toBe($antes);
})->group('RQ-06', 'RF-PD-03');

it('responde 400 a un JSON mal formado en las puertas de acceso, que no piden sesion', function (string $uri): void {
    problemResponsesRaw('POST', $uri, 'application/json', '{"email":1')
        ->assertStatus(400)
        ->assertJsonPath('type', 'urn:kronoqr:problem:bad-request');
})->with([
    'acceso al panel' => ['/api/v1/auth/login'],
    'acceso al portal' => ['/api/v1/me/login'],
])->group('RQ-06', 'RS-03');

it('responde 400 a un JSON mal formado en el fichaje, y no lo registra', function (): void {
    $escenario = AttendanceFixtures::scenario();

    problemResponsesRaw('POST', '/api/v1/scan', 'application/json', '{"scan_id":"x"', $escenario['token'])
        ->assertStatus(400)
        ->assertJsonPath('type', 'urn:kronoqr:problem:bad-request');

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RQ-06', 'RF-AT-01');

it('responde 415 a un cuerpo que no es JSON', function (string $tipo, string $cuerpo): void {
    problemResponsesRaw('POST', '/api/v1/auth/login', $tipo, $cuerpo)
        ->assertStatus(415)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'urn:kronoqr:problem:unsupported-media-type');
})->with([
    'texto plano' => ['text/plain', 'email=a@b.c&password=x'],
    'XML' => ['application/xml', '<login/>'],
    'formulario' => ['application/x-www-form-urlencoded', 'email=a%40b.c&password=x'],
    'sin tipo' => ['', '{"email":"a@b.c"}'],
])->group('RQ-06');

it('admite multipart solo en las rutas de subida', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // En una ruta JSON, un multipart es un tipo que no acepta.
    problemResponsesRaw('PATCH', '/api/v1/site', 'multipart/form-data; boundary=xyz', "--xyz--\r\n", $token)
        ->assertStatus(415);

    // En la de importacion pasa la puerta (y lo que responda es asunto suyo).
    expect(problemResponsesRaw('POST', '/api/v1/employees/import', 'multipart/form-data; boundary=xyz', "--xyz--\r\n", $token)->getStatusCode())
        ->not->toBe(415);
})->group('RQ-06', 'RF-GP-05');

it('deja pasar una peticion sin cuerpo', function (): void {
    $escenario = AttendanceFixtures::scenario();

    // Un POST sin cuerpo ni tipo no es un cuerpo ilegible: lo que responda es
    // del endpoint (aqui su propio `400 invalid-request`), no de esta puerta.
    expect(problemResponsesRaw('POST', '/api/v1/kiosk/heartbeat', '', '', $escenario['token'])->json('type'))
        ->not->toBeIn(['urn:kronoqr:problem:bad-request', 'urn:kronoqr:problem:unsupported-media-type']);
})->group('RQ-06');

it('responde title y detail en el idioma pedido, con Content-Language', function (): void {
    InstallationLocale::set('es', ['es', 'en']);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $ruta = '/api/v1/employees/019a0000-0000-7000-8000-0000000000aa';

    $ingles = Api::as($token)->withHeaders(['Accept-Language' => 'en'])->get($ruta);
    $espanol = Api::as($token)->withHeaders(['Accept-Language' => 'es'])->get($ruta);

    $ingles->assertStatus(404)
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('title', 'Resource not found')
        ->assertJsonPath('detail', 'The requested resource does not exist.');

    $espanol->assertStatus(404)
        ->assertHeader('Content-Language', 'es')
        ->assertJsonPath('title', 'Recurso no encontrado');
})->group('RQ-06', 'RF-PD-01');

it('traduce tambien el 400 y el 422', function (): void {
    InstallationLocale::set('es', ['es', 'en']);

    problemResponsesSend(Request::create('/api/v1/auth/login', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ACCEPT_LANGUAGE' => 'en',
    ], '{"email":'))
        ->assertStatus(400)
        ->assertJsonPath('title', 'Malformed request');

    Api::guest()->withHeaders(['Accept-Language' => 'en'])->post('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('title', 'Invalid request')
        ->assertJsonPath('detail', 'Check the fields indicated.');
})->group('RQ-06', 'RF-PD-01');

it('responde un 422 del framework como validacion fallida y con errors', function (): void {
    Route::middleware('api')->post('/api/v1/__problems/unprocessable', static fn (): never => abort(422, 'No existe ninguna persona con ese identificador.'));

    Api::guest()->post('/api/v1/__problems/unprocessable', ['x' => 1])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors']);
})->group('RQ-06');

it('da a un 503 que no es mantenimiento su type real, e instance', function (): void {
    Route::middleware('api')->get('/api/v1/__problems/unavailable', static fn (): never => abort(503));

    Api::guest()->get('/api/v1/__problems/unavailable?x=1')
        ->assertStatus(503)
        ->assertJsonPath('type', 'urn:kronoqr:problem:service-unavailable')
        ->assertJsonPath('instance', '/api/v1/__problems/unavailable')
        ->assertHeader('Retry-After');
})->group('RQ-06', 'RNF-D-03');
