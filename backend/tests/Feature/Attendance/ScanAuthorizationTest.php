<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device as DeviceModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: `POST /api/v1/scan` con su prueba de autorizacion
 * negativa **por cada rol no autorizado**, sin excepciones.
 *
 * Este endpoint es el unico del producto que **crea registro horario**, asi que
 * quien pueda llamarlo puede fabricar la jornada de otra persona. Por eso se
 * prueba por parejas y no con un caso representativo.
 *
 * Se comprueban los DOS controles del doc 02 §7.3, y por separado:
 *
 *   - **El ambito del token**, que verifica el middleware `ability`. Es lo que
 *     deja fuera a toda sesion de gestion: ninguna lleva `scan:write`.
 *   - **La policy**, que verifica QUIEN porta el token. Es lo que deja fuera a
 *     una cuenta que tuviera el ambito sin ser un quiosco.
 *
 * Que los dos devuelvan 403 es correcto: desde fuera no se distingue por que se
 * ha denegado.
 */

uses(RefreshDatabase::class);

/**
 * Un cuerpo valido, para que lo que falle sea la autorizacion y no la
 * validacion.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function escaneoValido(): array
{
    $scanId = Str::uuid7()->toString();

    return [$scanId, [
        'scan_id' => $scanId,
        'occurred_at' => '2026-03-14T07:02:31Z',
        'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
    ]];
}

it('no deja fichar sin token', function (): void {
    // La mitad de la regla dura 18 que mas se olvida: comprobar tambien que sin
    // credenciales no se entra.
    [$scanId, $cuerpo] = escaneoValido();

    Api::guest()
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', $cuerpo)
        ->assertStatus(401);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07');

it('deniega el fichaje a cada rol de gestion', function (UserRole $rol): void {
    // Ninguna sesion de gestion lleva `scan:write` (§7.3), asi que ni el
    // administrador de la instalacion puede fabricar un fichaje desde aqui. Para
    // corregir el registro horario existe la correccion manual trazada de
    // RF-PA-04, que deja autor y motivo (RN-13); un `admin` que pudiera llamar a
    // `/scan` produciria fichajes indistinguibles de los reales.
    [$scanId, $cuerpo] = escaneoValido();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));

    Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->with([
    'administrador' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
    'empleado' => [UserRole::EMPLEADO],
])->group('RS-04', 'RQ-07', 'RF-ID-02');

it('deniega el fichaje a una cuenta con rol kiosk que no es un dispositivo', function (): void {
    // El caso que la policy existe para cubrir y que el ambito NO cubre: esta
    // cuenta si lleva `scan:write`, asi que pasa el middleware `ability` y llega
    // al `FormRequest`. Lo que la para es que su `tokenable` es una fila de
    // `users` y no de `devices`.
    //
    // No es hipotetico: es lo que ocurriria si alguien decidiera «dar de alta un
    // usuario por tablet» en lugar de emparejar el dispositivo (RF-ID-04).
    [$scanId, $cuerpo] = escaneoValido();

    Api::as(ManagementUsers::kioskToken())
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-04');

it('deniega el fichaje a un quiosco cuyo token no tiene scan:write', function (): void {
    // El caso simetrico del anterior: el portador SI es un dispositivo, pero su
    // token solo puede leer el padron. Es lo que hace que un token de quiosco
    // comprometido no sirva para lo que no se le concedio (§7.3).
    [$scanId, $cuerpo] = escaneoValido();

    $site = WorkforceFixtures::site('Hotel sin ambito');
    $device = AttendanceFixtures::device($site);
    $sinAmbito = DeviceModel::query()->findOrFail($device['id'])
        ->createToken('Solo padron', [TokenAbility::ROSTER_READ->value])
        ->plainTextToken;

    Api::as($sinAmbito)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-04');

it('no revela nada sobre la credencial en una respuesta denegada', function (): void {
    // Regla dura 17 llevada al borde de la autorizacion: si el 403 dijera algo
    // distinto segun la tarjeta enviada, seria un oraculo tan util como el 422.
    // Se comprueba con una tarjeta que SI existe y con otra que no.
    $escenario = AttendanceFixtures::scenario();

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('tarjeta-buena', $escenario['employee']),
    );

    $cuerpos = [];

    foreach (['tarjeta-buena', 'tarjeta-inventada'] as $payload) {
        $scanId = Str::uuid7()->toString();

        $respuesta = Api::as(ManagementUsers::kioskToken())
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan', [
                'scan_id' => $scanId,
                'occurred_at' => '2026-03-14T07:02:31Z',
                'qr_payload' => $payload,
            ]);

        $respuesta->assertStatus(403);
        $cuerpos[] = $respuesta->json();
    }

    expect($cuerpos[0])->toBe($cuerpos[1]);
})->group('RS-03', 'RS-04');
