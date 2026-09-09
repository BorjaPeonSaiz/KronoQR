<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/diagnostics/errors` y `POST …/{id}/resolve` (RF-PD-15, Anexo B).
 *
 * Lo que se comprueba aqui es la pantalla completa que ve el IT del cliente: los
 * cinco filtros, el orden, la paginacion, los dos recuentos de la cabecera y la
 * resolucion idempotente. La autorizacion tiene su propio fichero.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/**
 * Un grupo de errores en la tabla, sin pasar por el sumidero.
 *
 * Se escribe directamente porque lo que esta prueba mide es la LECTURA: montar
 * cada fila provocando un error de verdad ataria estas afirmaciones a la
 * captacion, que es otra tarea y tiene sus propias pruebas.
 *
 * @param  array<string, mixed>  $columnas
 */
function grupoDeErrores(array $columnas = []): int
{
    $ahora = now()->toDateTimeString('microsecond');

    return (int) DB::table('error_events')->insertGetId(array_merge([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'error',
        'source' => 'api',
        'module' => 'attendance',
        'code' => null,
        'message' => 'algo fallo',
        'exception_class' => 'RuntimeException',
        'file' => 'app/Foo.php',
        'line' => 10,
        'context' => json_encode(['route' => '/api/v1/scan']),
        'trace_id' => str_repeat('a', 32),
        'device_id' => null,
        'employee_uuid' => null,
        'app_version' => '2.2.0',
        'occurrences' => 1,
        'first_seen_at' => $ahora,
        'last_seen_at' => $ahora,
        'resolved_at' => null,
        'resolved_by_user_id' => null,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ], $columnas));
}

function tokenDeAdmin(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('devuelve solo los abiertos cuando no se pide nada', function (): void {
    // `open` por omision es la pregunta de quien abre la pantalla: «¿que tengo
    // pendiente?». Sin ella serian noventa dias de todo con una columna de
    // estado.
    grupoDeErrores(['message' => 'sigue pasando']);
    grupoDeErrores(['message' => 'ya atendido', 'resolved_at' => now()->toDateTimeString('microsecond')]);

    $respuesta = Api::as(tokenDeAdmin())->get('/api/v1/diagnostics/errors');

    $respuesta->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', 'sigue pasando')
        ->assertJsonPath('meta.total', 1);
})->group('RF-PD-15');

it('filtra por origen, nivel, estado y periodo', function (): void {
    grupoDeErrores(['source' => 'worker', 'level' => 'critical', 'message' => 'cola']);
    grupoDeErrores(['source' => 'api', 'level' => 'error', 'message' => 'peticion']);
    grupoDeErrores([
        'source' => 'scheduler',
        'level' => 'critical',
        'message' => 'viejo',
        'last_seen_at' => now()->subDays(40)->toDateTimeString('microsecond'),
    ]);

    $token = tokenDeAdmin();

    Api::as($token)->get('/api/v1/diagnostics/errors?source=worker')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'cola');

    Api::as($token)->get('/api/v1/diagnostics/errors?level=error')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'peticion');

    // El periodo acota `last_seen_at`: lo que importa de un grupo es cuando paso
    // la ultima vez, no cuando empezo.
    Api::as($token)->get('/api/v1/diagnostics/errors?from='.now()->subDays(7)->toIso8601ZuluString())
        ->assertOk()->assertJsonCount(2, 'data');

    Api::as($token)->get('/api/v1/diagnostics/errors?to='.now()->subDays(30)->toIso8601ZuluString())
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'viejo');

    Api::as($token)->get('/api/v1/diagnostics/errors?status=all')
        ->assertOk()->assertJsonCount(3, 'data');
})->group('RF-PD-15');

it('ordena por la ultima vez que se vio, de lo mas reciente a lo mas antiguo', function (): void {
    grupoDeErrores(['message' => 'anteayer', 'last_seen_at' => now()->subDays(2)->toDateTimeString('microsecond')]);
    grupoDeErrores(['message' => 'hoy']);
    grupoDeErrores(['message' => 'ayer', 'last_seen_at' => now()->subDay()->toDateTimeString('microsecond')]);

    Api::as(tokenDeAdmin())->get('/api/v1/diagnostics/errors')
        ->assertOk()
        ->assertJsonPath('data.0.message', 'hoy')
        ->assertJsonPath('data.1.message', 'ayer')
        ->assertJsonPath('data.2.message', 'anteayer');
})->group('RF-PD-15');

it('pagina y devuelve un meta que el panel puede pintar', function (): void {
    foreach (range(1, 5) as $indice) {
        grupoDeErrores([
            'message' => 'grupo '.$indice,
            'last_seen_at' => now()->subMinutes($indice)->toDateTimeString('microsecond'),
        ]);
    }

    grupoDeErrores(['level' => 'critical', 'message' => 'critico']);

    $respuesta = Api::as(tokenDeAdmin())->get('/api/v1/diagnostics/errors?per_page=2&page=2');

    $respuesta->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.total_pages', 3)
        // Los dos recuentos son de TODA la instalacion, sin filtros: la cabecera
        // no puede decir «0 criticos» porque quien mira tenga un filtro puesto.
        ->assertJsonPath('meta.open_errors', 5)
        ->assertJsonPath('meta.open_critical', 1)
        // La zona del centro, para que la antiguedad no se calcule contra el
        // reloj del navegador (regla dura 3).
        ->assertJsonPath('meta.time_zone', 'Europe/Madrid');

    expect($respuesta->json('meta.generated_at'))->toBeString()->toEndWith('Z');
})->group('RF-PD-15');

it('los dos recuentos de la cabecera no cambian con los filtros', function (): void {
    grupoDeErrores(['source' => 'worker', 'level' => 'critical']);
    grupoDeErrores(['source' => 'api', 'level' => 'error']);

    Api::as(tokenDeAdmin())->get('/api/v1/diagnostics/errors?source=console')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.open_critical', 1)
        ->assertJsonPath('meta.open_errors', 1);
})->group('RF-PD-15');

it('rechaza un filtro que no existe en lugar de ignorarlo', function (): void {
    // Un `?severity=critical` -el nombre en ingles del campo que aqui es
    // `level`- devolveria el historico entero en silencio, y quien lo escribio
    // se iria convencido de estar mirando solo lo urgente.
    $token = tokenDeAdmin();

    Api::as($token)->get('/api/v1/diagnostics/errors?severity=critical')->assertStatus(422);
    Api::as($token)->get('/api/v1/diagnostics/errors?source=inventado')->assertStatus(422);
    Api::as($token)->get('/api/v1/diagnostics/errors?per_page=500')->assertStatus(422);
})->group('RF-PD-15');

it('resuelve un grupo y deja quien y cuando en la propia fila', function (): void {
    $id = grupoDeErrores();
    $usuario = ManagementUsers::withRole(UserRole::ADMIN);

    $respuesta = Api::as(ManagementUsers::tokenFor($usuario))
        ->post('/api/v1/diagnostics/errors/'.$id.'/resolve');

    $respuesta->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('resolved_by.name', $usuario->name);

    expect($respuesta->json('resolved_at'))->toBeString();

    $fila = DB::table('error_events')->where('id', $id)->first();

    expect($fila?->resolved_by_user_id)->toBe($usuario->id);
})->group('RF-PD-15');

it('resolver no escribe en audit_log: no es una accion con relevancia legal', function (): void {
    // Regla dura 6, en sentido inverso. La traza de quien y cuando vive en la
    // propia fila; meterla en `audit_log` mezclaria ruido de mantenimiento con
    // la evidencia que se conserva cuatro anos.
    $antes = DB::table('audit_log')->count();

    Api::as(tokenDeAdmin())->post('/api/v1/diagnostics/errors/'.grupoDeErrores().'/resolve')->assertOk();

    expect(DB::table('audit_log')->count())->toBe($antes);
})->group('RF-PD-15');

it('resolver dos veces devuelve la misma fila y no reescribe el autor', function (): void {
    $id = grupoDeErrores();
    $primera = ManagementUsers::withRole(UserRole::ADMIN, 'primera@hotel.test');
    $segunda = ManagementUsers::withRole(UserRole::ADMIN, 'segunda@hotel.test');

    $uno = Api::as(ManagementUsers::tokenFor($primera))
        ->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertOk();

    $dos = Api::as(ManagementUsers::tokenFor($segunda))
        ->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertOk();

    expect($dos->json('resolved_at'))->toBe($uno->json('resolved_at'))
        ->and($dos->json('resolved_by.uuid'))->toBe($uno->json('resolved_by.uuid'));
})->group('RF-PD-15');

it('responde 404 con un identificador que no existe', function (): void {
    Api::as(tokenDeAdmin())->post('/api/v1/diagnostics/errors/999999/resolve')->assertStatus(404);
})->group('RF-PD-15');

it('devuelve el contexto como objeto aunque este vacio', function (): void {
    // Un `[]` de PHP se serializa como array JSON y el contrato declara un
    // objeto: el cliente generado lo rechazaria.
    grupoDeErrores(['context' => '{}']);

    $respuesta = Api::as(tokenDeAdmin())->get('/api/v1/diagnostics/errors')->assertOk();

    expect($respuesta->json('data.0.context'))->toBe([]);

    $crudo = (string) $respuesta->getContent();

    expect($crudo)->toContain('"context":{}');
})->group('RF-PD-15');
