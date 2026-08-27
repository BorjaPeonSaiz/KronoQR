<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device as DeviceModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07 sobre los dos endpoints del modulo `Kiosk`:
 * `GET /api/v1/kiosk/roster` y `POST /api/v1/kiosk/heartbeat`.
 *
 * **Por que este fichero tenia que existir.** Hasta ahora el modulo entero no
 * tenia ni una prueba propia: `KioskPolicy` estaba escrita, bien argumentada y
 * enganchada... y nada lo comprobaba. Quien la desenganchara —un `authorize()`
 * que devolviera `true`, un `FormRequest` sustituido por validacion en el
 * controlador— habria dejado la suite entera en verde y el padron de la plantilla
 * al alcance de cualquier sesion autenticada.
 *
 * **Los dos controles del §7.3, y cruzados.** El endpoint pide dos cosas a la vez
 * —un AMBITO (`roster:read`, `heartbeat:write`) que verifica el middleware
 * `ability`, y un PORTADOR que sea un dispositivo, que verifica la policy—, asi
 * que se prueban los dos cuadrantes que fallan:
 *
 *   - portador correcto (un `devices`) sin el ambito;
 *   - ambito correcto sobre un portador que no es un dispositivo.
 *
 * Sin el segundo, la policy podria borrarse entera sin que fallara nada: todas
 * las sesiones de gestion se quedarian fuera igualmente por el ambito, hasta el
 * dia en que alguien emita un token con ambitos de mas.
 */

uses(RefreshDatabase::class);

/**
 * Los dos endpoints del quiosco, con un cuerpo valido para que lo que falle sea
 * la autorizacion y no la validacion.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function kioskEndpoints(): array
{
    return [
        'padron' => ['GET', '/api/v1/kiosk/roster', []],
        'latido' => ['POST', '/api/v1/kiosk/heartbeat', [
            'app_version' => '1.0.0',
            'pending_queue_size' => 0,
        ]],
    ];
}

it('no deja entrar a los endpoints del quiosco sin token', function (string $method, string $uri, array $body): void {
    Api::guest()->call($method, $uri, $body)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(kioskEndpoints())->group('RQ-07', 'RS-04', 'RF-KI-03');

it('no deja entrar a los endpoints del quiosco a ningun rol de gestion', function (string $method, string $uri, array $body, string $role): void {
    // Ni siquiera el administrador. La `KioskPolicy` lo dice sin matices: aqui no
    // hay una persona en un panel, hay una tablet colgada de una pared. Quien
    // necesite ver el estado de los quioscos tiene el panel de salud de RF-PA-07,
    // que es otro endpoint y otra potestad.
    //
    // Por PAREJAS rol x endpoint —dos conjuntos de datos encadenados, no un bucle
    // dentro de la prueba—: asi el informe dice cual de las diez combinaciones
    // fallo, en vez de parar en la primera.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(kioskEndpoints())->with([
    'admin' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
    'auditor' => UserRole::AUDITOR->value,
    'responsable de departamento' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
    'empleado' => UserRole::EMPLEADO->value,
])->group('RQ-07', 'RS-04', 'RF-ID-02');

it('no deja entrar a los endpoints del quiosco a una sesion de portal', function (string $method, string $uri, array $body): void {
    // `self:read` no alcanza el padron. Si lo alcanzara, cualquiera con su codigo
    // y su PIN se descargaria los nombres de toda la plantilla de su centro desde
    // el movil.
    $site = WorkforceFixtures::site('Hotel del portal');
    $token = PortalLogins::open(WorkforceFixtures::employee($site));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(kioskEndpoints())->group('RQ-07', 'RS-04', 'RF-ID-07');

it('no deja leer el padron a una sesion de gestion con roster:read puesto a mano', function (): void {
    // **El caso que separa la policy del ambito.** Este token SI lleva
    // `roster:read`, asi que pasa el middleware `ability` y llega al
    // `FormRequest`. Lo que lo para es que su `tokenable` es una fila de `users` y
    // no de `devices`.
    //
    // Sin esta prueba, `KioskPolicy::readRoster()` podria devolver `true` y toda
    // la suite seguiria en verde.
    $usuaria = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $usuaria->createToken('Sesion con el ambito del quiosco', [
        TokenAbility::ROSTER_READ->value,
    ])->plainTextToken;

    Api::as($token)->get('/api/v1/kiosk/roster')->assertStatus(403);
})->group('RQ-07', 'RS-04', 'RF-ID-04');

it('no deja latir a una sesion de gestion con heartbeat:write puesto a mano', function (): void {
    // El mismo caso sobre el otro endpoint, y no un caso representativo: la policy
    // tiene un metodo por endpoint (`readRoster`, `sendHeartbeat`) precisamente
    // para que se puedan romper por separado.
    $usuaria = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $usuaria->createToken('Sesion con el ambito del latido', [
        TokenAbility::HEARTBEAT_WRITE->value,
    ])->plainTextToken;

    Api::as($token)->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '1.0.0',
        'pending_queue_size' => 0,
    ])->assertStatus(403);
})->group('RQ-07', 'RS-04', 'RF-ID-04');

it('no deja leer el padron a un quiosco cuyo token solo puede latir', function (): void {
    // El cuadrante simetrico: el portador SI es un dispositivo, pero su token no
    // lleva `roster:read`. Es lo que hace comprobable la promesa del §7.3 de que
    // los tres ambitos del quiosco son tres llaves distintas y no una sola.
    $site = WorkforceFixtures::site('Hotel de un solo ambito');
    $device = AttendanceFixtures::device($site);

    $soloLatido = DeviceModel::query()->findOrFail($device['id'])
        ->createToken('Solo latido', [TokenAbility::HEARTBEAT_WRITE->value])
        ->plainTextToken;

    Api::as($soloLatido)->get('/api/v1/kiosk/roster')->assertStatus(403);
})->group('RQ-07', 'RS-04', 'RF-ID-04');

it('no deja latir a un quiosco cuyo token solo puede leer el padron', function (): void {
    $site = WorkforceFixtures::site('Hotel de un solo ambito');
    $device = AttendanceFixtures::device($site);

    $soloPadron = DeviceModel::query()->findOrFail($device['id'])
        ->createToken('Solo padron', [TokenAbility::ROSTER_READ->value])
        ->plainTextToken;

    Api::as($soloPadron)->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '1.0.0',
        'pending_queue_size' => 0,
    ])->assertStatus(403);
})->group('RQ-07', 'RS-04', 'RF-ID-04');

it('deja pasar al quiosco de verdad, que es lo que hace significativo el resto', function (): void {
    // El control positivo. Sin el, los diecisiete `403` de arriba pasarian
    // identicos con las dos rutas desregistradas.
    $escenario = AttendanceFixtures::scenario();

    Api::as($escenario['token'])->get('/api/v1/kiosk/roster')->assertStatus(200);

    Api::as($escenario['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '1.0.0',
        'pending_queue_size' => 0,
    ])->assertStatus(200);
})->group('RQ-07', 'RF-KI-03', 'RF-PA-07');
