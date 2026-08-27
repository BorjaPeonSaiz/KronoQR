<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device as DeviceModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: `POST /api/v1/scan/pin` con su prueba de autorizacion
 * negativa **por cada rol no autorizado**, sin excepciones (tarea 1.12).
 *
 * Este endpoint **crea registro horario** igual que `/scan`, y ademas lo hace a
 * partir de algo que se sabe en lugar de algo que se lleva encima. Quien pueda
 * llamarlo y conozca un codigo de empleado esta a seis digitos de fabricar la
 * jornada de otra persona, asi que se prueba por parejas y no con un caso
 * representativo.
 *
 * Se comprueban los DOS controles del doc 02 §7.3, y por separado:
 *
 *   - **El ambito del token** (`scan:write`), que verifica el middleware
 *     `ability`. Es lo que deja fuera a toda sesion de gestion.
 *   - **La policy** `ScanPolicy::recordByPin()`, que verifica QUIEN porta el
 *     token. Es lo que deja fuera a una cuenta que tuviera el ambito sin ser un
 *     quiosco.
 *
 * Que los dos devuelvan 403 es correcto: desde fuera no se distingue por que se
 * ha denegado.
 */

uses(RefreshDatabase::class);

const PIN_AUTORIZACION = '556219';

/**
 * Un cuerpo valido de verdad —empleado real, PIN real, sobre real— para que lo
 * que falle sea la autorizacion y no la validacion ni la verificacion.
 *
 * @return array{0: string, 1: array<string, string>, 2: array{site: int, employee: string, device: int, deviceUuid: string, token: string}}
 */
function fichajePorPinValido(): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_AUTORIZACION);

    $scanId = Str::uuid7()->toString();

    return [$scanId, [
        'scan_id' => $scanId,
        'occurred_at' => '2026-03-14T07:02:31Z',
        'employee_code' => EmployeePins::codeOf($escenario['employee']),
        'pin_sealed' => EmployeePins::seal(PIN_AUTORIZACION, EmployeePins::configureSealing()),
    ], $escenario];
}

it('no deja fichar por PIN sin token', function (): void {
    [$scanId, $cuerpo] = fichajePorPinValido();

    Api::guest()
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo)
        ->assertStatus(401);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-AT-11');

it('deniega el fichaje por PIN a cada rol de gestion', function (UserRole $rol): void {
    // Ninguna sesion de gestion lleva `scan:write` (§7.3). Aqui importa mas que
    // en `/scan`: un administrador que pudiera llamar a este endpoint fabricaria
    // fichajes indistinguibles de los reales sin necesitar siquiera la tarjeta
    // de nadie. Para rectificar el registro horario existe la correccion trazada
    // de RF-PA-04, que deja autor y motivo (RN-13).
    [$scanId, $cuerpo] = fichajePorPinValido();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));

    Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->with([
    'administrador' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
    'empleado' => [UserRole::EMPLEADO],
])->group('RS-04', 'RQ-07', 'RF-AT-11');

it('deniega el fichaje por PIN a una cuenta con rol kiosk que no es un dispositivo', function (): void {
    // El caso que la policy existe para cubrir y que el ambito NO cubre: esta
    // cuenta lleva `scan:write`, pasa el middleware `ability` y llega al
    // `FormRequest`. Lo que la para es que su `tokenable` es una fila de `users`
    // y no de `devices`.
    [$scanId, $cuerpo] = fichajePorPinValido();

    Api::as(ManagementUsers::kioskToken())
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-04');

it('deniega el fichaje por PIN a un quiosco cuyo token no tiene scan:write', function (): void {
    // El caso simetrico: el portador SI es un dispositivo, pero su token solo
    // puede leer el padron. Es lo que hace que un token de quiosco comprometido
    // no sirva para lo que no se le concedio (§7.3).
    [$scanId, $cuerpo] = fichajePorPinValido();

    $site = WorkforceFixtures::site('Hotel sin ambito');
    $device = AttendanceFixtures::device($site);
    $sinAmbito = DeviceModel::query()->findOrFail($device['id'])
        ->createToken('Solo padron', [TokenAbility::ROSTER_READ->value])
        ->plainTextToken;

    Api::as($sinAmbito)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-04');

it('deniega el fichaje por PIN a un token de portal', function (): void {
    // ADR-015 y §7.3: el token del portal lleva `self:read` y nada mas. Es el
    // MISMO PIN el que abre las dos puertas (regla dura 12), asi que hay que
    // comprobar explicitamente que una sesion de portal no puede fichar por
    // nadie — ni siquiera por si misma: el portal es de lectura.
    [$scanId, $cuerpo] = fichajePorPinValido();

    $usuario = ManagementUsers::withRole(UserRole::EMPLEADO);
    $tokenDePortal = $usuario->createToken('Portal', [TokenAbility::SELF_READ->value])->plainTextToken;

    Api::as($tokenDePortal)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-07');

it('no revela nada sobre el empleado ni sobre el PIN en una respuesta denegada', function (): void {
    // Regla dura 17 llevada al borde de la autorizacion: si el 403 dijera algo
    // distinto segun el codigo enviado, seria un comprobador de plantillas tan
    // util como el 422. Se comprueba con un codigo que SI existe y con otro que
    // no, y con el PIN bueno y con uno malo.
    [, $cuerpo] = fichajePorPinValido();

    $cuerpos = [];

    foreach ([$cuerpo['employee_code'], 'ENOEXISTE'] as $codigo) {
        $scanId = Str::uuid7()->toString();

        $respuesta = Api::as(ManagementUsers::kioskToken())
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan/pin', [...$cuerpo, 'scan_id' => $scanId, 'employee_code' => $codigo]);

        $respuesta->assertStatus(403);
        $cuerpos[] = $respuesta->json();
    }

    expect($cuerpos[0])->toBe($cuerpos[1]);
})->group('RS-03', 'RS-04');

it('limita el fichaje por PIN mucho antes que el fichaje por tarjeta', function (): void {
    // RS-12 y §7.5: el limite por dispositivo de esta zona es propio y dos
    // ordenes de magnitud mas bajo, porque lo que frena no es un ritmo de
    // fichaje sino fuerza bruta sobre un espacio de 10^6. Es un control
    // INDEPENDIENTE del bloqueo por empleado: aqui ni siquiera se llega a
    // comprobar ningun PIN.
    config()->set('kiosk.rate_limits.pin_scan_per_device', 3);

    [, $cuerpo, $escenario] = fichajePorPinValido();

    for ($i = 0; $i < 3; $i++) {
        $scanId = Str::uuid7()->toString();

        Api::as($escenario['token'])
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan/pin', [...$cuerpo, 'scan_id' => $scanId, 'employee_code' => 'ENOEXISTE'])
            ->assertStatus(422);
    }

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [...$cuerpo, 'scan_id' => $scanId, 'employee_code' => 'ENOEXISTE']);

    $respuesta->assertStatus(429)
        // `problem+json` tambien en el camino de error, con su `Retry-After`.
        ->assertHeader('Retry-After');

    expect($respuesta->json('type'))->toBe('urn:kronoqr:problem:too-many-requests');
})->group('RS-12', 'RS-02', 'RF-AT-11');
