<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Quien no puede cerrar sesion por `POST /api/v1/auth/logout` (RS-13, ADR-039,
 * regla dura 18; decision del propietario del 28-10-2026).
 *
 * La ruta es de la gestion y del soporte. Un quiosco recibe `403`: con el token
 * robado de una tablet, una llamada aqui la dejaba sin servicio sin asiento; su
 * credencial se retira desvinculandolo desde el panel. Una sesion del portal
 * tambien, porque cierra la suya por `POST /api/v1/me/logout`. En los dos casos
 * la denegacion no puede tener efecto: ni asiento, ni linea de cierre, ni token
 * revocado.
 */

uses(RefreshDatabase::class);

const DEVICE_LOGOUT_TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
});

/**
 * Llama a la ruta con el token dado y devuelve la respuesta y las lineas
 * `auth.logged_out` que deja.
 *
 * @return array{0: TestResponse<Response>, 1: list<array<string, mixed>>}
 */
function deviceLogoutLlamada(string $token): array
{
    $apuntes = [];

    Log::listen(function (MessageLogged $evento) use (&$apuntes): void {
        if ($evento->message === 'auth.logged_out') {
            $apuntes[] = $evento->context;
        }
    });

    $respuesta = Api::as($token)->post('/api/v1/auth/logout');

    // Artefacto de la suite: el guard de Sanctum cachea el usuario entre
    // peticiones de la misma prueba. Sin esto, lo que se llame despues no
    // volveria a autenticar el token.
    Auth::forgetGuards();

    return [$respuesta, $apuntes];
}

it('deniega el cierre de sesion a un quiosco sin asiento y con la tablet fichando despues', function (): void {
    $escenario = AttendanceFixtures::scenario();

    [$respuesta, $cierres] = deviceLogoutLlamada($escenario['token']);

    $respuesta->assertValidResponse(403);

    expect($cierres)->toBe([])
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0)
        ->and(PersonalAccessToken::findToken($escenario['token']))->not->toBeNull();

    // Y la tablet sigue fichando con el mismo token.
    FrozenTime::at('2026-03-14 07:02:31');
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(DEVICE_LOGOUT_TARJETA, $escenario['employee']),
    );
    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => DEVICE_LOGOUT_TARJETA,
        ])
        ->assertValidResponse(200);

    expect(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1);
})->group('RS-13', 'RS-08', 'RQ-07', 'RF-ID-01');

it('deniega el cierre de sesion a un token del portal sin asiento y con el token vivo', function (): void {
    // El portal cierra su sesion por `/me/logout`; por aqui, `403`.
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del control'));
    $token = PortalLogins::open($employee);

    [$respuesta, $cierres] = deviceLogoutLlamada($token);

    $respuesta->assertValidResponse(403);

    expect($cierres)->toBe([])
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0)
        ->and(PersonalAccessToken::findToken($token))->not->toBeNull();

    Api::as($token)->get('/api/v1/me/workdays')->assertOk();
})->group('RS-13', 'RS-08', 'RQ-07', 'RF-ID-05');

it('no deja linea de cierre ni asiento cuando llama un acceso de soporte', function (): void {
    // RF-PD-11 y ADR-020: un acceso de soporte no tiene sesion que cerrar. La
    // ruta le responde `204` y no le revoca nada.
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    [$respuesta, $cierres] = deviceLogoutLlamada($issued->token);

    $respuesta->assertStatus(204);

    expect($cierres)->toBe([])
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0)
        ->and(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('revoked_at'))->toBeNull();
})->group('RF-PD-11', 'RS-13');
