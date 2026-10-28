<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Escenario «Entrega registrada de la tarjeta» del doc 01 §11, por el panel
 * (`POST /api/v1/credentials/{uuid}/deliver`, RF-QR-06).
 *
 * El «Entonces» dice tres cosas y las tres se miran aqui: la fecha, el
 * empleado y el responsable de la entrega quedan en la credencial, y el acto
 * queda en `audit_log` a nombre de quien la entrego (G6 de la verificacion
 * 2.1.0). La consola tiene su propia prueba en `DeliverCredentialCommandTest`.
 */

uses(RefreshDatabase::class);

const DELIVER_ENDPOINT_AHORA = '2026-08-21 06:40:15';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at(DELIVER_ENDPOINT_AHORA);
    app()->instance(CardRenderer::class, new FakeCardRenderer);
});

/**
 * Una tarjeta impresa y pendiente de entregar, y la cuenta de RRHH que la
 * entrega.
 *
 * @return array{credential: string, employee: string, token: string, rrhh_id: int}
 */
function entregaPorPanelTarjetaImpresa(): array
{
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($rrhh);
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site());

    $credential = Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertStatus(201)
        ->json('uuid');

    expect($credential)->toBeString();
    /** @var string $credential */
    Api::as($token)->post('/api/v1/credentials/'.$credential.'/print')->assertStatus(200);

    return ['credential' => $credential, 'employee' => $employee, 'token' => $token, 'rrhh_id' => $rrhh->id];
}

it('registra la fecha, el empleado y el responsable de la entrega en la credencial', function (): void {
    $tarjeta = entregaPorPanelTarjetaImpresa();

    Api::as($tarjeta['token'])->post('/api/v1/credentials/'.$tarjeta['credential'].'/deliver')
        ->assertValidResponse(200)
        ->assertJsonPath('employee_uuid', $tarjeta['employee'])
        ->assertJsonPath('delivered_at', '2026-08-21T06:40:15.000000Z');

    /** @var object{delivered_at: string, delivered_by_user_id: int, employee_id: int} $fila */
    $fila = DB::table('credentials')->where('uuid', $tarjeta['credential'])->sole();

    expect($fila->delivered_by_user_id)->toBe($tarjeta['rrhh_id'])
        ->and(new DateTimeImmutable($fila->delivered_at))->toEqual(new DateTimeImmutable('2026-08-21T06:40:15Z'))
        ->and(DB::table('employees')->where('id', $fila->employee_id)->value('uuid'))->toBe($tarjeta['employee']);
})->group('RF-QR-06', 'RL-04');

it('deja el asiento credential.delivered a nombre de quien entrega, con el empleado', function (): void {
    $tarjeta = entregaPorPanelTarjetaImpresa();

    Api::as($tarjeta['token'])->post('/api/v1/credentials/'.$tarjeta['credential'].'/deliver')->assertStatus(200);

    /** @var object{actor_type: string, actor_id: int, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'credential.delivered')->sole();
    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        ->and($asiento->actor_id)->toBe($tarjeta['rrhh_id'])
        ->and($payload['delivered_by_user_id'])->toBe($tarjeta['rrhh_id'])
        ->and($payload['employee_uuid'])->toBe($tarjeta['employee']);
})->group('RF-QR-06', 'RS-07', 'RL-04');
