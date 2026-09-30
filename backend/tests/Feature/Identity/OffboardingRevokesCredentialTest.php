<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Identity\Application\UseCase\RevokeCredentialsOfOffboardedEmployee;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * N1 (RN-14, RF-GP-03): dar de baja a una persona le retira la credencial.
 *
 * Antes, la baja solo impedia fichar porque el escaneo mira el estado laboral:
 * la tarjeta seguia «activa» en el panel y en la rotacion, y la sesion del
 * portal seguia abierta. El contrato (`/employees/{uuid}/offboard`) afirmaba
 * que la credencial se revoca y nadie escuchaba el evento.
 *
 * Todo contra la tarjeta firmada de verdad (resolutor HMAC real) y el portal
 * real: lo que se prueba es que el QR deja de valer con el rechazo generico de
 * la regla dura 17, no que un doble diga que lo hace.
 */

uses(RefreshDatabase::class);

const OFFBOARDING_NOW = '2026-09-14 07:02:31';

beforeEach(function (): void {
    FrozenTime::at(OFFBOARDING_NOW);
    Spectator::using('openapi.yaml');
});

/**
 * @return TestResponse<Response>
 */
function escanearTrasLaBaja(string $deviceToken, string $payload): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($deviceToken)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-09-14T07:02:31Z',
            'qr_payload' => $payload,
        ]);
}

/**
 * @param  TestResponse<Response>  $respuesta
 * @return array<mixed>
 */
function cuerpoSinScanId(TestResponse $respuesta): array
{
    $cuerpo = (array) $respuesta->json();
    unset($cuerpo['scan_id']);

    return $cuerpo;
}

function darDeBaja(string $employeeUuid): void
{
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees/'.$employeeUuid.'/offboard', ['terminated_at' => '2026-09-14'])
        ->assertValidRequest()
        ->assertValidResponse(200);
}

it('tras la baja el QR deja de valer con el mismo rechazo que un codigo inexistente', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $tarjeta = Credentials::issueFor($employeeId)->toString();

    // Antes de la baja la tarjeta ficha: el rechazo posterior es por la baja.
    escanearTrasLaBaja($escenario['token'], $tarjeta)->assertOk();

    darDeBaja($escenario['employee']);

    $trasLaBaja = escanearTrasLaBaja($escenario['token'], $tarjeta);
    $inexistente = escanearTrasLaBaja($escenario['token'], Credentials::signedWithUnknownKey()->toString());

    $trasLaBaja->assertStatus(422)->assertValidResponse();
    $inexistente->assertStatus(422);

    // Regla dura 17: ni una pista de la causa. El detalle queda en el servidor.
    expect(cuerpoSinScanId($trasLaBaja))->toBe(cuerpoSinScanId($inexistente))
        ->and($trasLaBaja->json('type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and(DB::table('scan_events')->where('scan_id', $trasLaBaja->json('scan_id'))->value('result'))
        ->toBe('rejected_revoked');
})->group('RN-14', 'RF-GP-03', 'RS-03', 'RF-QR-03');

it('revoca todas las credenciales activas, tambien la pendiente de imprimir, con su asiento', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    Credentials::issueFor($employeeId);
    Credentials::pendingFor($employeeId);
    // Una ya revocada antes no se toca ni se vuelve a asentar.
    Credentials::issueFor($employeeId, Credentials::previousKey(), 'lost');

    darDeBaja($escenario['employee']);

    $credenciales = DB::table('credentials')->where('employee_id', $employeeId)->get();

    expect($credenciales->whereNull('revoked_at'))->toHaveCount(0)
        ->and($credenciales->where('revoked_reason', RevokeCredentialsOfOffboardedEmployee::REASON))->toHaveCount(2)
        ->and($credenciales->where('revoked_reason', 'lost'))->toHaveCount(1);

    $asientos = DB::table('audit_log')->where('action', 'credential.revoked')->get();

    expect($asientos)->toHaveCount(2);

    foreach ($asientos as $asiento) {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $asiento->payload, true, flags: JSON_THROW_ON_ERROR);

        expect($payload['employee_uuid'])->toBe($escenario['employee'])
            ->and($payload['reason'])->toBe(RevokeCredentialsOfOffboardedEmployee::REASON);
    }

    // La cadena sigue integra con los asientos nuevos (regla dura 6, RS-07).
    expect(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RN-14', 'RF-GP-03', 'RF-QR-03', 'RL-04');

it('cierra la sesion del portal que ya estaba abierta y no deja abrir otra', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $sesion = PortalLogins::open($escenario['employee']);

    Api::as($sesion)->get('/api/v1/me/workdays')->assertStatus(200);

    darDeBaja($escenario['employee']);

    Api::as($sesion)->get('/api/v1/me/workdays')->assertStatus(401);

    // El inicio de sesion nuevo da el mismo 401 generico que un PIN incorrecto.
    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => EmployeePins::codeOf($escenario['employee']),
        'pin' => PortalLogins::PIN,
    ])->assertStatus(401);
})->group('RN-14', 'RF-GP-03', 'RF-ID-06');

it('es idempotente: una segunda pasada no revoca ni asienta nada', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    Credentials::issueFor($employeeId);

    darDeBaja($escenario['employee']);

    $revocar = app(RevokeCredentialsOfOffboardedEmployee::class);
    $antes = DB::table('audit_log')->count();

    expect($revocar->handle($escenario['employee'], new DateTimeImmutable('2026-09-14T07:05:00Z')))->toBe(0)
        ->and(DB::table('audit_log')->count())->toBe($antes);
})->group('RN-14');

it('una baja que falla no deja la credencial revocada', function (): void {
    // Misma transaccion: la baja repetida es un 409 y no revoca nada nuevo.
    $escenario = AttendanceFixtures::scenario();
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);

    darDeBaja($escenario['employee']);
    Credentials::issueFor($employeeId);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-09-14'])
        ->assertStatus(409);

    expect(DB::table('credentials')->where('employee_id', $employeeId)->whereNull('revoked_at')->count())->toBe(1);
})->group('RN-14', 'RF-GP-03');
