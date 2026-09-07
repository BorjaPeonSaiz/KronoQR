<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Lo que el emparejamiento deja escrito y lo que deja de funcionar**
 * (RF-PD-06, regla dura 6, RL-04, RL-12).
 *
 * Tres cosas, y las tres son consecuencias que no se ven desde la respuesta HTTP:
 *
 *   1. **El asiento de `audit_log`.** Dar de alta un quiosco es crear un origen
 *      de fichajes: todo lo que despues entre por esa tablet acaba en el registro
 *      horario, y quien investigue una discrepancia necesita saber quien lo
 *      autorizo y cuando.
 *   2. **Que el actor es el correcto en las dos vias.** Desde el panel firma la
 *      persona; desde `kiosk:pairing-code` no hay sesion y firma `system`.
 *      Atribuirselo a la ultima cuenta que entro al panel seria falsificar el
 *      trail.
 *   3. **Que desvincular tiene efecto en la peticion siguiente**, no en 90 dias.
 *      Es la respuesta a una tablet robada y no puede depender de que caduque
 *      nada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:00:00'));
});

/**
 * @return array{code: string, pairing_id: string, pairing_secret: string}
 */
function solicitudNueva(): array
{
    /** @var array{code: string, pairing_id: string, pairing_secret: string} $ticket */
    $ticket = Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();

    return $ticket;
}

it('escribe device.provisioned con el administrador que lo confirmo', function (): void {
    WorkforceFixtures::site();

    $administradora = ManagementUsers::withRole(UserRole::ADMIN);
    $ticket = solicitudNueva();

    /** @var string $uuid */
    $uuid = Api::as(ManagementUsers::tokenFor($administradora))
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $ticket['code'], 'name' => 'Recepcion'])
        ->assertOk()
        ->json('device.uuid');

    /** @var object{actor_type: string, actor_id: int|null, subject_type: string, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'device.provisioned')->firstOrFail();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        ->and($asiento->actor_id)->toBe($administradora->id)
        ->and($asiento->subject_type)->toBe('device')
        ->and($payload['device_uuid'])->toBe($uuid)
        ->and($payload['name'])->toBe('Recepcion')
        ->and($payload['pairing_request_uuid'])->toBe($ticket['pairing_id'])
        ->and($payload['reactivated'])->toBeFalse()
        ->and($payload['previous_status'])->toBeNull()
        // La via, como hace la rotacion dentro de `device.paired`.
        ->and($payload['via'])->toBe('pairing_code');
})->group('RF-PD-06', 'RL-04');

it('distingue el alta de la reactivacion en el asiento', function (): void {
    // Quien lea el trail necesita distinguir «se instalo un puesto nuevo» de «se
    // cambio el aparato del puesto de siempre»: la segunda frase explica por que
    // hay un hueco en los fichajes de ese quiosco y la primera no (ADR-028).
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $primero = solicitudNueva();
    /** @var string $uuid */
    $uuid = Api::as($token)
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $primero['code'], 'name' => 'Recepcion'])
        ->json('device.uuid');

    Api::as($token)->post('/api/v1/devices/'.$uuid.'/unpair')->assertOk();

    $segundo = solicitudNueva();
    Api::as($token)
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $segundo['code'], 'name' => 'Recepcion'])
        ->assertOk();

    /** @var list<object{payload: string}> $asientos */
    $asientos = DB::table('audit_log')
        ->where('action', 'device.provisioned')
        ->orderBy('id')
        ->get(['payload'])
        ->all();

    expect($asientos)->toHaveCount(2);

    /** @var array<string, mixed> $reactivacion */
    $reactivacion = json_decode($asientos[1]->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($reactivacion['reactivated'])->toBeTrue()
        // Contra que se comparo, que es lo que hace el asiento reconstruible.
        ->and($reactivacion['previous_status'])->toBe('revoked');
})->group('RF-PD-06', 'RL-04');

it('separa el alta del puesto de la entrega de la llave', function (): void {
    // Dos hechos y dos asientos: `device.provisioned` lo firma una persona en el
    // `confirm` y `device.paired` ocurre despues, cuando la tablet recoge su
    // token, y es anonimo por construccion. Separarlos permite ver que entre uno
    // y otro pasaron ocho minutos, o que nunca llego a pasar nada.
    WorkforceFixtures::site();
    $ticket = solicitudNueva();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $ticket['code'], 'name' => 'Recepcion'])
        ->assertOk();

    // Todavia no hay llave entregada.
    expect(DB::table('audit_log')->where('action', 'device.paired')->count())->toBe(0);

    Api::guest()->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $ticket['pairing_id'],
        'pairing_secret' => $ticket['pairing_secret'],
    ])->assertOk();

    /** @var object{actor_type: string, actor_id: int|null} $entrega */
    $entrega = DB::table('audit_log')->where('action', 'device.paired')->firstOrFail();

    // **El `claim` es anonimo y su asiento lo dice.** Quien firmo el alta es el
    // administrador, en el asiento de arriba; este no tiene persona detras y
    // atribuirsela seria mentir.
    expect($entrega->actor_type)->toBe('system')
        ->and($entrega->actor_id)->toBeNull();
})->group('RF-PD-06', 'RL-04');

it('firma como system el emparejamiento hecho desde consola', function (): void {
    // La via alternativa del Anexo C. Un comando no tiene sesion, y el catalogo
    // de `AuditActorType` contempla `system` justo para esto.
    WorkforceFixtures::site();
    $ticket = solicitudNueva();

    [$exitCode, $salida] = Commands::run('kiosk:pairing-code '.$ticket['code'].' --name=Cocina');

    expect($exitCode)->toBe(0, $salida)
        // Y dice lo que ha hecho: «Vinculado», no «Reactivado».
        ->and($salida)->toContain('Vinculado');

    /** @var object{actor_type: string, actor_id: int|null, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'device.provisioned')->firstOrFail();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('system')
        ->and($asiento->actor_id)->toBeNull()
        ->and($payload['name'])->toBe('Cocina');

    // Y el quiosco esta de verdad dado de alta y activo.
    expect(DB::table('devices')->where('name', 'Cocina')->value('status'))->toBe('active');
})->group('RF-PD-06', 'RL-04');

it('deja fuera al quiosco en la peticion siguiente al desvincularlo', function (): void {
    // RL-12 y la mitigacion del doc 01 §8.1. La purga del padron cacheado la hace
    // la PWA al recibir el `401`; el servidor lo que hace es **dejar de
    // reconocerlo**, y tiene que ser en la peticion siguiente y no en 90 dias:
    // es la respuesta a una tablet robada.
    WorkforceFixtures::site();
    $panel = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $ticket = solicitudNueva();
    /** @var string $uuid */
    $uuid = Api::as($panel)
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $ticket['code'], 'name' => 'Recepcion'])
        ->json('device.uuid');

    /** @var string $tokenDelQuiosco */
    $tokenDelQuiosco = Api::guest()->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $ticket['pairing_id'],
        'pairing_secret' => $ticket['pairing_secret'],
    ])->json('token.value');

    Auth::forgetGuards();

    // Antes de desvincular, el quiosco trabaja.
    Api::as($tokenDelQuiosco)->get('/api/v1/kiosk/roster')->assertOk();

    Auth::forgetGuards();

    Api::as($panel)->post('/api/v1/devices/'.$uuid.'/unpair')->assertOk();

    // Artefacto de la suite, no del producto: las llamadas de una misma prueba
    // comparten aplicacion y el guard de Sanctum cachea lo que ya resolvio.
    Auth::forgetGuards();

    Api::as($tokenDelQuiosco)->get('/api/v1/kiosk/roster')->assertStatus(401);

    Auth::forgetGuards();

    Api::as($tokenDelQuiosco)
        ->post('/api/v1/kiosk/heartbeat', ['app_version' => '1.4.2', 'pending_queue_size' => 0])
        ->assertStatus(401);

    // Y queda el asiento de la revocacion con su motivo y su actor.
    /** @var object{actor_type: string, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'device.revoked')->firstOrFail();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        ->and($payload['reason'])->toBe('unpaired')
        ->and($payload['device_uuid'])->toBe($uuid);
})->group('RF-PD-06', 'RL-12');
