<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La suscripcion a un canal es un endpoint mas** (ADR-011, RF-ID-03, RS-04,
 * regla dura 18).
 *
 * Se autoriza en `POST /api/v1/broadcasting/auth` con los mismos dos controles
 * que cualquier ruta del §7.3 —el ambito del token y la policy del recurso— y
 * ademas con el alcance por departamento. Sin estas pruebas, el tiempo real
 * seria una puerta trasera a datos que el endpoint de sondeo si acota: un
 * responsable de cocina viendo entrar y salir a la gente de recepcion.
 *
 * **`403` es lo que devuelve Laravel cuando el callback del canal dice que no.**
 * Desde fuera no se distingue «ese canal no existe» de «no puedes entrar», que
 * es lo correcto: distinguirlo diria cuantos departamentos hay.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El responsable no esta entre los roles que RS-06 obliga a segundo factor.
    config()->set('identity.two_factor.required_roles', []);

    /*
     * **El difusor de verdad, y no el `null` del resto de la suite.**
     *
     * `NullBroadcaster::auth()` no comprueba nada: firma cualquier canal a
     * cualquiera. Con el, estas pruebas darian verde con `routes/channels.php`
     * vacio, que es exactamente el fallo que buscan. El difusor de produccion
     * —el de Pusher, que es el que usa Reverb— si ejecuta el callback del canal
     * y responde `403` cuando dice que no.
     *
     * Las credenciales son de mentira **y no hace falta que lo sean menos**: la
     * autorizacion de un canal privado se firma con un HMAC local y no habla con
     * ningun servidor. Aqui no se levanta ningun WebSocket.
     */
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
    config()->set('broadcasting.connections.reverb.secret', 'kronoqr-test-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'kronoqr-test');

    /*
     * Y **los canales se vuelven a declarar**, que no es un adorno: los registra
     * `Broadcast::channel()` sobre el difusor ACTIVO en ese momento, y en el
     * arranque de la suite el activo es `null`. Sin esta linea, el difusor de
     * Pusher nace sin ningun canal y todo responde `403` —incluidos los casos
     * positivos—, que es una suite en verde por el motivo equivocado.
     *
     * Es literalmente lo que hace `bootstrap/app.php` al arrancar una
     * instalacion: cargar `routes/channels.php`. Los callbacks son los de
     * produccion, no dobles.
     */
    require base_path('routes/channels.php');

    /*
     * Y una licencia que conceda el tiempo real (tarea 5.3, ADR-023).
     *
     * Desde la 5.3, el callback del canal comprueba el FeatureGate antes que
     * nada: sin licencia que lo conceda, **ningun canal se firma**. Sin esta
     * linea, todas las pruebas de aqui —incluidos los controles positivos—
     * responderian 403 por el motivo equivocado.
     *
     * Que la degradacion llegue hasta aqui es lo que la hace real y no
     * cooperativa: un cliente que ignorase meta.realtime.enabled conservaria
     * el tiempo real intacto si la unica comprobacion viviera en la respuesta.
     */
    LicenseKeys::grantAll();
});

/**
 * Cocina con su responsable y recepcion sin el.
 *
 * @return array{site: int, cocina: int, recepcion: int, jefeDeCocina: string}
 */
function escenarioDeCanales(): array
{
    $site = WorkforceFixtures::site('Hotel de canales', 'Europe/Madrid');
    $jefe = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefe->id]);

    return [
        'site' => $site,
        'cocina' => $cocina,
        'recepcion' => WorkforceFixtures::department($site, 'Recepcion'),
        'jefeDeCocina' => ManagementUsers::tokenFor($jefe),
    ];
}

/**
 * La peticion que hace la libreria del cliente al reconectar: el nombre del
 * canal tal y como viaja por el cable —con el prefijo `private-`— y el
 * identificador de socket que Reverb acaba de asignar.
 *
 * @return TestResponse<Response>
 */
function autorizarCanal(string $token, string $canal): TestResponse
{
    return Api::as($token)->post('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-'.$canal,
    ]);
}

it('firma al responsable la suscripcion al canal de su departamento', function (): void {
    // El control positivo. Sin el, todo lo de abajo pasaria igual con la
    // autorizacion de canales rota del todo.
    $escenario = escenarioDeCanales();

    $respuesta = autorizarCanal($escenario['jefeDeCocina'], 'presence.department.'.$escenario['cocina']);

    $respuesta->assertOk();

    expect($respuesta->json('auth'))->toBeString();
})->group('RF-ID-03', 'RS-04', 'RF-PA-01');

it('niega al responsable el canal de otro departamento', function (): void {
    $escenario = escenarioDeCanales();

    autorizarCanal($escenario['jefeDeCocina'], 'presence.department.'.$escenario['recepcion'])
        ->assertStatus(403);
})->group('RF-ID-03', 'RS-04');

it('niega al responsable el canal global de la instalacion', function (): void {
    // Aunque hoy dirigiera todos los departamentos que existen: su alcance es una
    // lista, y la lista se queda corta el dia que se cree uno nuevo.
    $escenario = escenarioDeCanales();

    autorizarCanal($escenario['jefeDeCocina'], 'presence.all')->assertStatus(403);
})->group('RF-ID-03', 'RS-04');

it('niega el canal a un auditor, que tiene el ambito pero no el rol', function (): void {
    // `auditor` lleva `attendance:read` (§7.3), asi que pasa el middleware y lo
    // que le para es la policy. Es exactamente para lo que sirven los dos
    // controles.
    escenarioDeCanales();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    autorizarCanal($token, 'presence.all')->assertStatus(403);
})->group('RF-ID-03', 'RS-04');

it('niega el canal a un empleado y a un token de quiosco', function (string $rol): void {
    // Al quiosco y al portal les falta el ambito `attendance:read`, asi que ni
    // llegan al callback del canal. El resultado desde fuera es el mismo `403`.
    escenarioDeCanales();

    $token = $rol === 'kiosk'
        ? ManagementUsers::kioskToken()
        : ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO));

    autorizarCanal($token, 'presence.all')->assertStatus(403);
    autorizarCanal($token, 'presence.department.1')->assertStatus(403);
})->with(['kiosk', 'empleado'])->group('RF-ID-03', 'RS-04', 'RF-ID-04');

it('niega el canal a un token de dispositivo real, que es el caso que importa', function (): void {
    // El token de una tablet colgada en una pared: sus tres ambitos son
    // `scan:write`, `roster:read` y `heartbeat:write`, y ninguno abre esto.
    $escenario = escenarioDeCanales();
    $device = AttendanceFixtures::device($escenario['site']);

    autorizarCanal(AttendanceFixtures::tokenFor($device['id']), 'presence.all')->assertStatus(403);
})->group('RS-04', 'RF-ID-04');

it('deniega sin token', function (): void {
    escenarioDeCanales();

    Api::guest()->post('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-presence.all',
    ])->assertStatus(401);
})->group('RS-04');

it('no confunde un nombre de canal con basura detras del numero', function (): void {
    // `(int) '3abc'` es `3`, y eso seria una autorizacion concedida a un canal que
    // nadie pidio. Por eso el callback exige digitos y nada mas.
    $escenario = escenarioDeCanales();

    autorizarCanal($escenario['jefeDeCocina'], 'presence.department.'.$escenario['cocina'].'abc')
        ->assertStatus(403);
})->group('RF-ID-03', 'RS-04');

it('firma a RRHH el canal global', function (): void {
    escenarioDeCanales();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    autorizarCanal($token, 'presence.all')->assertOk();
})->group('RF-PA-01');

/*
 * **EL FABRICANTE TAMPOCO ENTRA POR EL CABLE** (decision del 24-09-2026; regla
 * dura 16, ADR-020, RL-19, RF-PD-11).
 *
 * `LivePresencePolicy` cierra `GET /attendance/live` a todo actor de soporte, y
 * este fichero es la otra mitad de esa decision: los dos canales preguntan por
 * **la misma habilidad** (`Gate::allows('view', PresenceBoard::class)` en
 * `routes/channels.php`), asi que cerrar solo el endpoint dejaria al fabricante
 * sin la foto y **con el flujo en vivo intacto**, que es peor que no haber
 * cerrado nada: la vigilancia en tiempo real seguiria llegandole, y encima sin
 * pasar por la ruta que escribe el asiento de divulgacion.
 *
 * Que hoy compartan la habilidad no basta para no probarlo. Es precisamente
 * cuando dos caminos dependen de la misma linea cuando hace falta una prueba por
 * camino: el dia que el canal tenga su propio criterio —y ya tiene uno propio,
 * el `FeatureGate`— nadie se acordara de que esto dependia de aquello.
 */
it('no firma ningun canal de presencia a un acceso de soporte del fabricante', function (SupportScope $alcance): void {
    // arrange
    // El token se concede ANTES de montar el escenario y de cualquier viaje en
    // el tiempo: la vigencia de una concesion se comprueba contra `time()`
    // —infraestructura de sesion, no dominio— mientras que `expires_at` se
    // calcularia con el reloj detenido. Concedida despues, nacera caducada y la
    // peticion respondera `401`, que es un rechazo por el motivo equivocado.
    // Este fichero no detiene el reloj hoy; el orden se deja escrito para que
    // siga siendo correcto el dia que alguien lo detenga.
    $token = SupportGrants::tokenFor($alcance);
    $escenario = escenarioDeCanales();

    // act / assert
    autorizarCanal($token, 'presence.all')->assertStatus(403);
    autorizarCanal($token, 'presence.department.'.$escenario['cocina'])->assertStatus(403);
})->with([
    // Se para en el middleware: no lleva `attendance:read`.
    'diagnostics' => [SupportScope::Diagnostics],
    // **PASA EL MIDDLEWARE** —lleva el ambito— y ante las policies se presenta
    // como `admin` (`SupportScope::actsAs()`). Lo unico que lo para es la
    // pregunta por quien actua. Es el caso que importa; los otros dos estan
    // porque lo que se afirma es que NINGUNO pasa, no donde se para cada uno.
    'read_only' => [SupportScope::ReadOnly],
    // Se para en el middleware: lleva `settings:*`, no el registro horario.
    'configuration' => [SupportScope::Configuration],
])->group('RF-PA-01', 'RF-PD-11', 'ADR-020');

it('firma a un admin del cliente los dos canales que le niega al soporte', function (): void {
    // El control positivo de la prueba de arriba, y con los MISMOS dos canales:
    // sin el, cerrar los canales a todo el mundo —o dejarlos sin declarar—
    // pasaria en verde, y la presencia en vivo es la pantalla con la que
    // recepcion sabe quien esta dentro del hotel.
    $escenario = escenarioDeCanales();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    autorizarCanal($token, 'presence.all')->assertOk();
    autorizarCanal($token, 'presence.department.'.$escenario['cocina'])->assertOk();
})->group('RF-PA-01', 'RF-PD-11', 'RQ-07');
