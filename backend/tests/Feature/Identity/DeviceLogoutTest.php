<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El rastro de `POST /api/v1/auth/logout` segun quien llama (RS-13, ADR-039,
 * hallazgo de la tarea 22.6).
 *
 * Un quiosco emparejado que cerraba su sesion caia en el descarte del portal:
 * la linea `auth.logged_out` salia en el canal `portal` con el UUID del
 * dispositivo en `employee_uuid`. No filtraba datos personales, pero falseaba la
 * traza de la plantilla: una consulta por `employee_uuid` en Loki devolvia una
 * tablet. Ahora va al canal `device`, con `device_uuid`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
});

/**
 * Llama a la ruta con el token dado y devuelve las lineas `auth.logged_out` que
 * deja.
 *
 * @return list<array<string, mixed>>
 */
function deviceLogoutCierres(string $token): array
{
    $apuntes = [];

    Log::listen(function (MessageLogged $evento) use (&$apuntes): void {
        if ($evento->message === 'auth.logged_out') {
            $apuntes[] = $evento->context;
        }
    });

    Api::as($token)->post('/api/v1/auth/logout')->assertStatus(204);

    return $apuntes;
}

it('deja el cierre de sesion de un quiosco en el canal device con su uuid y sin employee_uuid', function (): void {
    $token = AttendanceFixtures::kioskToken();

    $deviceId = PersonalAccessToken::findToken($token)?->tokenable_id;
    $deviceUuid = DB::table('devices')->where('id', $deviceId)->value('uuid');

    expect($deviceUuid)->toBeString();

    $cierres = deviceLogoutCierres($token);

    expect($cierres)->toHaveCount(1)
        ->and($cierres[0]['channel'])->toBe('device')
        ->and($cierres[0]['device_uuid'])->toBe($deviceUuid)
        ->and($cierres[0]['subject_uuid'])->toBe($deviceUuid)
        ->and($cierres[0])->not->toHaveKey('employee_uuid')
        // ADR-039 reserva el asiento de cierre al panel.
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0)
        // Y cerrar sesion sigue siendo revocar el token de esta llamada.
        ->and(PersonalAccessToken::findToken($token))->toBeNull();
})->group('RS-13', 'RS-08', 'RF-ID-05');

it('deja igual el cierre de sesion del portal por la misma ruta', function (): void {
    // Control: el arreglo del quiosco no puede mover al empleado de canal ni
    // quitarle su `employee_uuid`.
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del control'));
    $token = PortalLogins::open($employee);

    $cierres = deviceLogoutCierres($token);

    expect($cierres)->toHaveCount(1)
        ->and($cierres[0]['channel'])->toBe('portal')
        ->and($cierres[0]['employee_uuid'])->toBe($employee)
        ->and($cierres[0]['subject_uuid'])->toBe($employee)
        ->and($cierres[0])->not->toHaveKey('device_uuid')
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0)
        ->and(PersonalAccessToken::findToken($token))->toBeNull();
})->group('RS-13', 'RS-08', 'RF-ID-05');

it('no deja linea de cierre ni asiento cuando llama un acceso de soporte', function (): void {
    // RF-PD-11 y ADR-020: un acceso de soporte no tiene sesion que cerrar. Ni
    // aparece como empleado en el canal del portal ni como dispositivo.
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    expect(deviceLogoutCierres($issued->token))->toBe([])
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0)
        ->and(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('revoked_at'))->toBeNull();
})->group('RF-PD-11', 'RS-13');
