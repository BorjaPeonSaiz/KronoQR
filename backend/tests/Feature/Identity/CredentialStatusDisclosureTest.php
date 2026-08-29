<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RF-QR-08 y RS-05 — el panel de estado de credenciales reparte la plantilla
 * nominal, y eso deja constancia.
 *
 * `GET /credentials/status` devuelve nombre completo, codigo de empleado, centro
 * y departamento de TODA la plantilla activa: es el conjunto de datos personales
 * mas completo que expone la API. RL-15 exige poder determinar el alcance de una
 * brecha a partir del trail; sin asiento, para este conjunto no se podia.
 *
 * La excepcion es `employee_uuid`: acotado a una persona esto es la ficha de
 * empleado, y ADR-037 decide que la ficha no deja asiento.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, department: int}
 */
function credentialStatusContext(int $employees = 3): array
{
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);

    for ($i = 0; $i < $employees; $i++) {
        WorkforceFixtures::employee($site, $department);
    }

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'department' => $department,
    ];
}

it('deja asiento de la divulgacion al abrir el panel de credenciales', function (): void {
    $contexto = credentialStatusContext();

    expect(DB::table('audit_log')->count())->toBe(0);

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status')
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{action: string, actor_type: string, subject_type: string, payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->action)->toBe('personal_data.accessed')
        ->and($asiento->actor_type)->toBe('user')
        ->and($asiento->subject_type)->toBe('personal_data')
        ->and($payload)->toMatchArray([
            'dataset' => 'credential_status',
            // El recuento es lo que convierte «alguien miro» en «alguien se
            // llevo el directorio entero».
            'record_count' => 3,
            'pending_only' => false,
        ]);
})->group('RF-QR-08', 'RS-05', 'RL-15');

it('registra el alcance del panel y jamas lo divulgado', function (): void {
    // Regla dura 21: un `audit_log` que copiara lo que se leyo seria una segunda
    // copia del directorio, con cuatro años de retencion y en la tabla que se
    // enseña en una inspeccion.
    $contexto = credentialStatusContext();

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['pending' => 1])
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'dataset' => 'credential_status',
        'pending_only' => true,
    ])
        ->and($asiento->payload)->not->toContain('Persona')
        ->and($asiento->payload)->not->toContain('De Prueba');
})->group('RF-QR-08', 'RS-05');

it('no deja asiento cuando el planificador solo publica metricas', function (): void {
    // `--quiet-table` es la forma en que el planificador lo ejecuta cada hora:
    // no imprime ni un nombre, solo el resumen por centro. Un asiento aqui
    // afirmaria que alguien accedio a la plantilla cuando no accedio nadie, y
    // 8.760 al año estorbarian a quien intente acotar una brecha (RL-15).
    credentialStatusContext();

    // `Artisan::call()` y no el `$this->artisan()` fluido: dentro de una clausura
    // de Pest, el segundo no tiene tipo y PHPStan 9 no lo resuelve (mismo motivo
    // que documenta Tests\Feature\Quality\Support\Commands).
    $salida = Artisan::call('credentials:status', ['--quiet-table' => true, '--no-metrics' => true]);

    expect($salida)->toBe(0)
        ->and(DB::table('audit_log')->count())->toBe(0);
})->group('RF-QR-08', 'RS-05');

it('deja asiento cuando el comando saca la tabla nominal por la terminal', function (): void {
    // Sacar el directorio por una terminal es la misma divulgacion que sacarlo
    // por la API. El actor es `system` porque quien tiene shell no se autentica
    // en la aplicacion; lo que importa aqui es que el hecho conste.
    credentialStatusContext();

    expect(Artisan::call('credentials:status', ['--no-metrics' => true]))->toBe(0);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{action: string, actor_type: string, payload: string} $asiento */
    expect($asiento->action)->toBe('personal_data.accessed')
        ->and($asiento->actor_type)->toBe('system')
        ->and($asiento->payload)->toContain('credential_status');
})->group('RF-QR-08', 'RS-05');

it('no deja asiento cuando la consulta se acota a una sola persona', function (): void {
    // ADR-037 §Decision, condicion 3: deja asiento la lectura de un CONJUNTO de
    // personas. Acotado a una, esto es la ficha de empleado —«`GET
    // /employees/{uuid}` (ficha) | No»— y se trata igual: quien puede acotar
    // puede pedir el tablero entero, que si deja asiento, y repetir el apunte
    // en cada ficha llenaria `audit_log` de la operativa ordinaria de RRHH
    // ademas de tomar el candado global de la cadena de hash en cada apertura.
    $contexto = credentialStatusContext();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => $uuid])
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data');

    expect(DB::table('audit_log')->count())->toBe(0);
})->group('RF-QR-08', 'RS-05', 'RL-15');

it('tampoco deja asiento cuando el UUID pedido no devuelve ninguna fila', function (): void {
    // Si no auditara la lectura que SI encuentra a alguien, auditar la que no
    // encuentra a nadie convertiria el trail en un oraculo de que UUID existen.
    $contexto = credentialStatusContext();

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => Str::uuid7()->toString()])
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'data');

    expect(DB::table('audit_log')->count())->toBe(0);
})->group('RF-QR-08', 'RS-05');

it('sigue dejando asiento del tablero completo aunque la ficha no lo deje', function (): void {
    // El contrapeso de la prueba anterior: lo que ADR-037 exime es la persona,
    // no el panel. Si esta falla junto con las de arriba, lo que se ha roto es
    // la auditoria entera y no la excepcion de la ficha.
    $contexto = credentialStatusContext();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => $uuid])
        ->assertValidResponse(200);

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status')
        ->assertValidResponse(200);

    expect(DB::table('audit_log')->count())->toBe(1);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    /** @var object{payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'dataset' => 'credential_status',
        'record_count' => 4,
    ]);
})->group('RF-QR-08', 'RS-05', 'RL-15');

it('no mete `employee_uuid` en el asiento del tablero sin filtrar', function (): void {
    // La ausencia de la clave es informacion: significa que el alcance fue el
    // centro o la instalacion entera, que es el caso que mas importa saber.
    $contexto = credentialStatusContext();

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status')
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->not->toHaveKey('employee_uuid');
})->group('RF-QR-08', 'RS-05');
