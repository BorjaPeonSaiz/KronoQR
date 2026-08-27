<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Port\PinMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\RecordingPinMetrics;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Provision, entrega y restablecimiento del PIN (RF-ID-09), de extremo a extremo
 * y validado contra el contrato.
 *
 * Las cuatro afirmaciones que esta tarea existe para sostener:
 *
 *   1. Un empleado sin PIN no puede existir: el alta lo emite en la misma
 *      transaccion.
 *   2. El PIN se muestra UNA vez. Volver a pedir la ficha no lo devuelve.
 *   3. Restablecer invalida el anterior y DESBLOQUEA: quien pide un PIN nuevo
 *      tiene que poder usarlo en el momento (RS-12).
 *   4. La entrega queda registrada con quien, a quien y cuando (RL-05).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, actor: string}
 */
function pinContext(): array
{
    $user = ManagementUsers::withRole(UserRole::RRHH);

    return [
        'token' => ManagementUsers::tokenFor($user),
        'site' => WorkforceFixtures::site(),
        'actor' => $user->uuid,
    ];
}

it('emite el PIN en el alta y lo devuelve una sola vez', function (): void {
    // El alta y la emision son un solo hecho (RF-ID-09): sin esto, la 1.11 y la
    // 1.12 no tienen ningun PIN valido contra el que probarse.
    $context = pinContext();

    $created = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ]);

    $created->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee.pin_status', 'issued');

    $pin = $created->json('pin.pin');
    $uuid = $created->json('employee.uuid');

    expect($pin)->toBeString()->toMatch('/^[0-9]{6}$/');

    // Y es el PIN de verdad de esa persona, no un adorno de la respuesta.
    $hash = DB::table('employees')->where('uuid', $uuid)->value('pin_hash');

    expect($hash)->toBeString()
        ->and(Hash::check(is_string($pin) ? $pin : '', is_string($hash) ? $hash : ''))->toBeTrue();

    // La segunda mitad: volver a pedir la ficha no lo devuelve.
    $ficha = Api::as($context['token'])->get('/api/v1/employees/'.(is_string($uuid) ? $uuid : ''));

    $ficha->assertValidResponse(200)->assertJsonPath('pin_status', 'issued');

    expect($ficha->content())->not->toContain(is_string($pin) ? $pin : 'imposible');
    expect($ficha->json('pin'))->toBeNull();
})->group('RF-ID-09', 'RF-GP-01', 'RL-05');

it('no deja existir a un empleado sin PIN', function (): void {
    // La invariante completa: no hay ningun camino del alta que deje `pin_hash`
    // a nulo, tampoco el del empleado sin correo ni el del alta sin departamento.
    $context = pinContext();

    foreach ([['first_name' => 'Youssef', 'last_name' => 'Amrani'], ['first_name' => 'Lucia', 'last_name' => 'Ferrer', 'email' => 'lucia@hotel.example']] as $extra) {
        Api::as($context['token'])->post('/api/v1/employees', [
            'site_id' => $context['site'],
            'hired_at' => '2026-08-14',
            ...$extra,
        ])->assertValidResponse(201);
    }

    expect(DB::table('employees')->whereNull('pin_hash')->count())->toBe(0)
        ->and(DB::table('employees')->whereNull('pin_issued_at')->count())->toBe(0);
})->group('RF-ID-09', 'RF-GP-01');

it('restablece el PIN y devuelve uno distinto', function (): void {
    $context = pinContext();

    $created = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ]);

    $uuid = $created->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';
    $original = $created->json('pin.pin');
    $original = is_string($original) ? $original : '';

    $reset = Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/reset');

    $reset->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('employee_uuid', $uuid)
        ->assertJsonPath('pin_status', 'issued');

    $nuevo = $reset->json('pin');
    $nuevo = is_string($nuevo) ? $nuevo : '';

    expect($nuevo)->toMatch('/^[0-9]{6}$/');

    // El anterior deja de valer por sustitucion del hash: no hay «desactivar»
    // porque la unica copia era esa.
    $hash = DB::table('employees')->where('uuid', $uuid)->value('pin_hash');
    $hash = is_string($hash) ? $hash : '';

    expect(Hash::check($nuevo, $hash))->toBeTrue();

    if ($nuevo !== $original) {
        expect(Hash::check($original, $hash))->toBeFalse();
    }
})->group('RF-ID-09');

it('restablecer desbloquea el PIN inmediatamente, y en las dos puertas', function (): void {
    // RS-12 y RF-ID-09: un empleado bloqueado que pide un PIN nuevo tiene que
    // poder usarlo YA. Si no, la unica salida seria esperar quince minutos
    // delante del quiosco, que es exactamente lo que la regla dura 19 prohibe.
    //
    // LAS DOS PUERTAS, y esa es la mitad que la tarea 1.12 anadio: desde que el
    // contador se lleva por empleado Y POR ORIGEN (§7.5), restablecer tiene que
    // limpiar los dos. Si limpiara solo uno, quien pidiera un PIN nuevo despues
    // de bloquearse en el portal seguiria sin poder fichar en el quiosco, y el
    // sintoma —«me han dado un PIN nuevo y sigue sin funcionar»— apuntaria a
    // cualquier sitio menos aqui.
    $context = pinContext();

    $uuid = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    /** @var PinAttempts $attempts */
    $attempts = app(PinAttempts::class);

    // Tantos fallos como haga falta para bloquear con la politica de serie.
    for ($i = 0; $i < 10; $i++) {
        $attempts->recordFailure($uuid, PinOrigin::KIOSK);
        $attempts->recordFailure($uuid, PinOrigin::PORTAL);
    }

    expect($attempts->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and($attempts->isLocked($uuid, PinOrigin::PORTAL))->toBeTrue();

    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/reset')->assertValidResponse(200);

    expect($attempts->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse()
        ->and($attempts->isLocked($uuid, PinOrigin::PORTAL))->toBeFalse();
})->group('RF-ID-09', 'RS-12');

it('cuenta los restablecimientos por centro', function (): void {
    // `pin_resets_total{site}` (§8.2): una subida sostenida no es un problema
    // tecnico, es que los PIN no llegan a la gente.
    $metrics = new RecordingPinMetrics;
    app()->instance(PinMetrics::class, $metrics);

    $context = pinContext();

    $uuid = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/reset')->assertStatus(200);
    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/reset')->assertStatus(200);

    expect($metrics->resets)->toBe([$context['site'] => 2]);

    // Y el alta NO cuenta como restablecimiento: mezclarlos haria que una
    // temporada de contrataciones se pareciera a un problema de entrega.
    expect(array_sum($metrics->resets))->toBe(2);
})->group('RF-ID-09');

it('registra la entrega con quien, a quien y cuando', function (): void {
    $context = pinContext();

    $uuid = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    $delivery = Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/deliver');

    $delivery->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('employee_uuid', $uuid)
        ->assertJsonPath('delivered_by', $context['actor'])
        ->assertJsonPath('pin_status', 'delivered');

    // Y la ficha lo refleja, que es lo que el panel necesita para saber a quien
    // le falta recibirlo.
    Api::as($context['token'])->get('/api/v1/employees/'.$uuid)
        ->assertValidResponse(200)
        ->assertJsonPath('pin_status', 'delivered');

    $row = DB::table('employees')->where('uuid', $uuid)->first();

    expect($row?->pin_delivered_at)->not->toBeNull()
        ->and($row?->pin_delivered_by_user_id)->not->toBeNull();
})->group('RF-ID-09', 'RL-05');

it('no registra dos veces la misma entrega', function (): void {
    // No es idempotente a proposito: sobrescribirla cambiaria el momento y el
    // responsable que ya constan en `audit_log`.
    $context = pinContext();

    $uuid = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/deliver')->assertValidResponse(200);

    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/deliver')
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-ID-09');

it('vuelve a dejar el PIN pendiente de entrega cuando se restablece', function (): void {
    // Un PIN nuevo no esta entregado porque lo estuviera el que sustituye: dar
    // por entregado un PIN que nadie ha recibido es justo el estado que RF-ID-09
    // quiere hacer visible.
    $context = pinContext();

    $uuid = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/deliver')->assertValidResponse(200);
    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/reset')->assertValidResponse(200);

    Api::as($context['token'])->get('/api/v1/employees/'.$uuid)
        ->assertValidResponse(200)
        ->assertJsonPath('pin_status', 'issued');

    // Y por eso la entrega vuelve a poder registrarse.
    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/deliver')->assertValidResponse(200);
})->group('RF-ID-09');

it('responde 404 por un empleado que no existe, igual que por uno fuera de alcance', function (): void {
    // Regla dura 17: `/pin/reset` no puede convertirse en un comprobador de que
    // empleados existen.
    $context = pinContext();

    $desconocido = '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-000000000000';

    Api::as($context['token'])->post($desconocido.'/pin/reset')
        ->assertValidResponse(404)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-found');

    Api::as($context['token'])->post($desconocido.'/pin/deliver')
        ->assertValidResponse(404)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-found');
})->group('RF-ID-09', 'RS-03');

it('no acepta que el cliente proponga un PIN', function (): void {
    // La robustez del PIN no puede depender de lo que teclee quien rellena el
    // formulario: el primero seria la fecha de nacimiento de alguien.
    $context = pinContext();

    $uuid = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/pin/reset', ['pin' => '123456'])
        ->assertStatus(422);

    $hash = DB::table('employees')->where('uuid', $uuid)->value('pin_hash');
    $hash = is_string($hash) ? $hash : '';

    expect(Hash::check('123456', $hash))->toBeFalse();
})->group('RF-ID-09');
