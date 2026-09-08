<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Product\Infrastructure\Persistence\SupportGrant as SupportGrantModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las tres rutas de `/api/v1/support/grants` (RF-PD-11, RL-18, ADR-020, regla
 * dura 16).
 *
 * LO QUE SE COMPRUEBA AQUI es que el mecanismo hace lo que el requisito promete:
 * el token sale una sola vez, la lista es visible para el cliente con las
 * revocadas y las caducadas dentro, revocar es idempotente y nada se borra.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013), y una respuesta que no lo cumple rompe el
 * cliente TypeScript generado de el — que en esta tarea pinta la unica pantalla
 * desde la que se corta el acceso del fabricante.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
});

function supportAdminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/**
 * Cuantos tokens de Sanctum cuelgan de una CONCESION de soporte.
 *
 * Se filtra por el `tokenable`: en la misma tabla viven tambien las sesiones del
 * panel, y contarlas todas haria que estas pruebas dijeran cosas sobre el token
 * del administrador que las ejecuta.
 */
function tokensDeSoporte(): int
{
    return DB::table('personal_access_tokens')
        ->where('tokenable_type', SupportGrantModel::class)
        ->count();
}

it('concede un acceso y devuelve el token UNA vez', function (): void {
    $response = Api::as(supportAdminToken())
        ->post('/api/v1/support/grants', [
            'reason' => 'Incidencia #123: la cola del quiosco de recepcion no vacia',
            'scope' => 'diagnostics',
            'hours' => 24,
        ])
        ->assertValidResponse(201);

    $token = $response->json('data.token');

    expect($token)->toBeString();
    expect($token)->not->toBe('');
    expect($response->json('data.status'))->toBe('active')
        ->and($response->json('data.scope'))->toBe('diagnostics')
        ->and($response->json('data.accessed_at'))->toBeNull()
        ->and($response->json('data.revoked_at'))->toBeNull();

    // Y NO SE GUARDA EN CLARO EN NINGUN SITIO. Solo su hash, como en `devices`.
    $row = DB::table('support_grants')->first();

    expect($row?->token_hash)->toBeString()
        ->and($row?->token_hash)->not->toBe($token)
        ->and(DB::table('support_grants')->where('token_hash', $token)->count())->toBe(0);
})->group('RF-PD-11', 'RL-18');

it('no hay forma de volver a pedir el token', function (): void {
    // No hay `GET /support/grants/{uuid}` y la lista no lo lleva. Un endpoint
    // para «volver a verlo» convertiria la concesion en una credencial permanente
    // recuperable por cualquiera que entre luego al panel (ADR-020).
    SupportGrants::issue();

    $response = Api::as(supportAdminToken())->get('/api/v1/support/grants')->assertValidResponse(200);

    expect($response->json('data.0.token'))->toBeNull();
})->group('RF-PD-11');

it('el alcance de serie es el mas estrecho', function (): void {
    // Quien concede deprisa, en mitad de una incidencia, acaba con el minimo.
    $response = Api::as(supportAdminToken())
        ->post('/api/v1/support/grants', ['reason' => 'Incidencia #99'])
        ->assertValidResponse(201);

    expect($response->json('data.scope'))->toBe('diagnostics');
})->group('RF-PD-11');

it('rechaza un motivo vacio y una duracion por encima del tope', function (array $body): void {
    Api::as(supportAdminToken())->post('/api/v1/support/grants', $body)->assertStatus(422);

    expect(DB::table('support_grants')->count())->toBe(0);
})->with([
    'sin motivo' => [['hours' => 24]],
    'motivo demasiado corto' => [['reason' => 'ab']],
    'motivo demasiado largo' => [['reason' => str_repeat('x', 201)]],
    'mas horas que el tope' => [['reason' => 'Incidencia #1', 'hours' => 73]],
    'cero horas' => [['reason' => 'Incidencia #1', 'hours' => 0]],
    'campo que el endpoint no conoce' => [['reason' => 'Incidencia #1', 'granted_by' => 4]],
])->group('RF-PD-11');

it('lista las concesiones de la mas reciente a la mas antigua, con las cerradas dentro', function (): void {
    // Nada se borra (regla dura 5). Una lista que enseñara solo las activas
    // respondería «no hay ningun acceso» a «¿ha entrado alguien alguna vez?»:
    // literalmente cierta y completamente engañosa.
    $revocada = SupportGrants::issue(reason: 'Incidencia #1');
    $caducada = SupportGrants::issue(reason: 'Incidencia #2');
    $activa = SupportGrants::issue(reason: 'Incidencia #3');

    SupportGrants::expire($caducada->grant->uuid);
    Api::as(supportAdminToken())->delete('/api/v1/support/grants/'.$revocada->grant->uuid)->assertStatus(204);

    $response = Api::as(supportAdminToken())->get('/api/v1/support/grants')->assertValidResponse(200);

    /** @var list<array{uuid: string, status: string}> $data */
    $data = $response->json('data');
    $estados = [];

    foreach ($data as $fila) {
        $estados[$fila['uuid']] = $fila['status'];
    }

    expect($data)->toHaveCount(3)
        ->and($estados[$activa->grant->uuid])->toBe('active')
        ->and($estados[$caducada->grant->uuid])->toBe('expired')
        ->and($estados[$revocada->grant->uuid])->toBe('revoked');
})->group('RF-PD-11');

it('la lista dice quien la concedio, para que y cuando caduca', function (): void {
    // La mitad «visible para el cliente» del requisito: sin nombre, un UUID no
    // responde «¿quien autorizo el acceso del martes?».
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    SupportGrants::issue(reason: 'Incidencia #123', grantedByUserId: $admin->id);

    $response = Api::as(supportAdminToken())->get('/api/v1/support/grants')->assertValidResponse(200);

    expect($response->json('data.0.reason'))->toBe('Incidencia #123')
        ->and($response->json('data.0.granted_by.uuid'))->toBe($admin->uuid)
        ->and($response->json('data.0.granted_by.name'))->toBe($admin->name)
        ->and($response->json('data.0.expires_at'))->toBeString();
})->group('RF-PD-11');

it('revocar es idempotente y no borra la fila', function (): void {
    $issued = SupportGrants::issue();

    Api::as(supportAdminToken())->delete('/api/v1/support/grants/'.$issued->grant->uuid)->assertStatus(204);
    // La segunda pulsacion del boton: mismo codigo, sin volver a auditar.
    Api::as(supportAdminToken())->delete('/api/v1/support/grants/'.$issued->grant->uuid)->assertStatus(204);

    expect(DB::table('support_grants')->count())->toBe(1)
        ->and(DB::table('support_grants')->first()?->revoked_at)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'support_grant.revoked')->count())->toBe(1);
})->group('RF-PD-11', 'RL-04');

it('revocar una concesion que no existe es 404', function (): void {
    Api::as(supportAdminToken())
        ->delete('/api/v1/support/grants/0199f6a2-4c1e-7d3b-8a90-000000000000')
        ->assertStatus(404);
})->group('RF-PD-11');

it('revocar retira el token en el acto', function (): void {
    $issued = SupportGrants::issue();

    expect(tokensDeSoporte())->toBe(1);

    Api::as(supportAdminToken())->delete('/api/v1/support/grants/'.$issued->grant->uuid)->assertStatus(204);

    // Lo que se borra es la llave, no el registro: la concesion sigue entera con
    // su `revoked_at`. Sin esto, «revocar» seria cosmetico y el token seguiria
    // autenticando hasta caducar.
    expect(tokensDeSoporte())->toBe(0)
        ->and(DB::table('support_grants')->count())->toBe(1);
})->group('RF-PD-11');

it('con la licencia caducada se concede y se revoca igual', function (): void {
    // Regla dura 15 y ADR-019: es CUANDO MAS FALTA HACE. La incidencia puede ser
    // justamente que la renovacion no se activa.
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])));

    $response = Api::as(supportAdminToken())
        ->post('/api/v1/support/grants', ['reason' => 'Incidencia #123 con licencia caducada'])
        ->assertValidResponse(201);

    /** @var string $uuid */
    $uuid = $response->json('data.uuid');

    Api::as(supportAdminToken())->get('/api/v1/support/grants')->assertValidResponse(200);
    Api::as(supportAdminToken())->delete('/api/v1/support/grants/'.$uuid)->assertStatus(204);
})->group('RF-PD-11', 'RF-PD-05');

it('el alcance pedido es el que se concede', function (string $scope): void {
    $response = Api::as(supportAdminToken())
        ->post('/api/v1/support/grants', ['reason' => 'Incidencia #123', 'scope' => $scope])
        ->assertValidResponse(201);

    expect($response->json('data.scope'))->toBe($scope);
})->with(array_map(
    static fn (SupportScope $scope): string => $scope->value,
    SupportScope::cases(),
))->group('RF-PD-11');
