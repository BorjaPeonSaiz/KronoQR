<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/credentials` se niega a emitir o reemitir una tarjeta a una
 * persona de baja (RN-14, RF-QR-01).
 *
 * `409` validado contra el contrato, con el texto en el idioma de la peticion,
 * y **nada tocado**: ni tarjeta nueva, ni la anterior revocada, ni asiento. La
 * reemision es el caso que importa: revocar primero y fallar despues dejaria a
 * la persona sin tarjeta y con un asiento `credential.revoked` que describe un
 * acto que no llego a completarse.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, employee: string, employeeId: int}
 */
function offboardedCredentialContext(): array
{
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employee' => $employee,
        'employeeId' => (int) DB::table('employees')->where('uuid', $employee)->value('id'), // @phpstan-ignore cast.int (`value()` devuelve `mixed`; la columna es `bigint` y existe porque se acaba de crear)
    ];
}

it('no emite una tarjeta a una persona de baja y no deja rastro', function (): void {
    $context = offboardedCredentialContext();
    WorkforceFixtures::terminate($context['employee']);

    Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee']])
        ->assertValidRequest()
        ->assertValidResponse(409)
        ->assertJsonPath('detail', Lang::get('credentials.errors.holder_offboarded', [], 'es'));

    expect(DB::table('credentials')->where('employee_id', $context['employeeId'])->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', AuditAction::CredentialIssued->value)->count())->toBe(0);
})->group('RN-14', 'RF-QR-01');

it('no reemite a una persona de baja ni revoca la tarjeta que ya tenia', function (): void {
    $context = offboardedCredentialContext();

    Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee']])
        ->assertValidResponse(201);

    // La fila se pasa a baja sin el caso de uso, que ya revocaria la tarjeta:
    // lo que se prueba es la guarda de la emision sobre el estado de la ficha.
    WorkforceFixtures::terminate($context['employee']);

    Api::as($context['token'])
        ->post('/api/v1/credentials', [
            'employee_uuid' => $context['employee'],
            'reissue' => true,
            'reason' => 'Tarjeta extraviada',
        ])
        ->assertValidRequest()
        ->assertValidResponse(409);

    expect(DB::table('credentials')->where('employee_id', $context['employeeId'])->count())->toBe(1)
        ->and(DB::table('credentials')->where('employee_id', $context['employeeId'])->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', AuditAction::CredentialRevoked->value)->count())->toBe(0);
})->group('RN-14', 'RF-QR-01');

it('responde en ingles cuando la peticion lo pide', function (): void {
    $context = offboardedCredentialContext();
    WorkforceFixtures::terminate($context['employee']);

    Api::as($context['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee']])
        ->assertValidResponse(409)
        ->assertJsonPath('detail', Lang::get('credentials.errors.holder_offboarded', [], 'en'));
})->group('RN-14', 'RF-QR-01');

it('sigue emitiendo a quien esta suspendido', function (): void {
    $context = offboardedCredentialContext();
    DB::table('employees')->where('uuid', $context['employee'])->update(['status' => 'suspended']);

    Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee']])
        ->assertValidResponse(201);
})->group('RN-14', 'RF-QR-01');

it('tampoco la emite por consola', function (): void {
    $context = offboardedCredentialContext();
    WorkforceFixtures::terminate($context['employee']);

    expect(Artisan::call('credentials:issue', ['employee' => $context['employee']]))->toBe(1)
        ->and(DB::table('credentials')->where('employee_id', $context['employeeId'])->count())->toBe(0);
})->group('RN-14', 'RF-QR-01');
