<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
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

    // Desde el bloque 12 de la 2.3.0 la ruta tiene operacion en el contrato
    // (B3, R6-BD-02), y sus respuestas se validan contra el.
    Spectator::using('openapi.yaml');
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

    $respuesta->assertOk()->assertValidResponse(200);

    expect($respuesta->json('auth'))->toBeString();
})->group('RF-ID-03', 'RS-04', 'RF-PA-01');

it('niega al responsable el canal de otro departamento', function (): void {
    $escenario = escenarioDeCanales();

    autorizarCanal($escenario['jefeDeCocina'], 'presence.department.'.$escenario['recepcion'])
        ->assertStatus(403)
        ->assertValidResponse(403);
})->group('RF-ID-03', 'RS-04');

it('niega al responsable el canal global de la instalacion', function (): void {
    // Aunque hoy dirigiera todos los departamentos que existen: su alcance es una
    // lista, y la lista se queda corta el dia que se cree uno nuevo.
    $escenario = escenarioDeCanales();

    autorizarCanal($escenario['jefeDeCocina'], 'presence.all')->assertStatus(403);
})->group('RF-ID-03', 'RS-04');

it('niega los dos canales a un auditor, tambien con un token que aun tenga el ambito', function (Closure $token, Closure $canal): void {
    // Desde la 2.2.1 el `auditor` solo lleva `reports:legal` (RF-ID-02) y no
    // pasa el middleware. Un token emitido antes conserva `attendance:read`
    // hasta que caduca, pasa el middleware, y lo que le para es la policy. Es
    // exactamente para lo que sirven los dos controles. Y en los dos canales:
    // el de un departamento tiene su propio criterio en `routes/channels.php`,
    // y el token antiguo no se habia pedido contra el (V21-4-QA-1).
    $escenario = escenarioDeCanales();

    autorizarCanal($token(ManagementUsers::withRole(UserRole::AUDITOR)), $canal($escenario))->assertStatus(403);
})->with([
    'token actual' => [static fn (User $auditor): string => ManagementUsers::tokenFor($auditor)],
    'token anterior a la 2.2.1' => [static fn (User $auditor): string => ManagementUsers::legacyAuditorTokenFor($auditor)],
])->with([
    'canal global' => [static fn (array $escenario): string => 'presence.all'],
    'canal de un departamento' => [static fn (array $escenario): string => 'presence.department.'.$escenario['cocina']],
])->group('RF-ID-03', 'RF-ID-02', 'RS-04');

it('niega los dos canales a una sesion de portal y a un quiosco', function (Closure $portador): void {
    // Al quiosco y al portal les falta el ambito `attendance:read`, asi que ni
    // llegan al callback del canal. Los dos con el token que emite el producto:
    // la sesion de `POST /me/login` y el de un dispositivo emparejado, cuyos tres
    // ambitos son `scan:write`, `roster:read` y `heartbeat:write` (A3, R4-QA-07).
    $escenario = escenarioDeCanales();
    $token = $portador($escenario['site']);

    autorizarCanal($token, 'presence.all')->assertStatus(403);
    autorizarCanal($token, 'presence.department.'.$escenario['cocina'])->assertStatus(403);
})->with([
    'sesion de portal' => [static fn (int $site): string => PortalLogins::open(WorkforceFixtures::employee($site))],
    'quiosco' => [static fn (int $site): string => AttendanceFixtures::tokenFor(AttendanceFixtures::device($site)['id'])],
])->group('RF-ID-03', 'RS-04', 'RF-ID-04');

it('deniega sin token', function (): void {
    escenarioDeCanales();

    Api::guest()->post('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-presence.all',
    ])->assertStatus(401)->assertValidResponse(401);
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

/*
 * LA PETICION MAL FORMADA ES UN 422, NO UN 500 (B3, R6-BD-02; bloque 12 de la
 * 2.3.0). El controlador del framework pasaba `socket_id` tal cual a la libreria
 * de Pusher, que revienta con uno que no tiene la forma `123.456`; y respondia
 * JSONP si llegaba un `callback`. Ninguna de las dos formas esta en el contrato.
 */
it('rechaza con 422 un socket_id mal formado o ausente, y un callback de JSONP', function (array $cuerpo): void {
    escenarioDeCanales();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->post('/api/v1/broadcasting/auth', $cuerpo)
        ->assertStatus(422)
        ->assertValidResponse(422);

    expect(DB::table('error_events')->count())->toBe(0);
})->with([
    'socket_id sin punto' => [['socket_id' => 'abc', 'channel_name' => 'private-presence.all']],
    'sin socket_id' => [['channel_name' => 'private-presence.all']],
    'sin canal' => [['socket_id' => '1234.5678']],
    'con callback' => [['socket_id' => '1234.5678', 'channel_name' => 'private-presence.all', 'callback' => 'robar']],
])->group('RF-PA-01', 'RQ-06');

it('firma tambien el formulario que envia el cliente de Pusher', function (): void {
    escenarioDeCanales();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    // Un formulario, como lo envia `pusher-js` con su autorizador de serie;
    // `Api` siempre envia JSON. Por el kernel HTTP, como `call()` de la prueba.
    $peticion = Request::create('/api/v1/broadcasting/auth', 'POST', ['socket_id' => '1234.5678', 'channel_name' => 'private-presence.all'], server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);
    $kernel = app(HttpKernel::class);
    $respuesta = TestResponse::fromBaseResponse($kernel->handle($peticion), $peticion);
    $kernel->terminate($peticion, $respuesta->baseResponse);

    $respuesta->assertOk();
    expect($respuesta->json('auth'))->toStartWith('kronoqr-test-key:');
})->group('RF-PA-01');

it('solo atiende POST: el GET que registraba el framework ya no existe', function (): void {
    escenarioDeCanales();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->get('/api/v1/broadcasting/auth?socket_id=1234.5678&channel_name=private-presence.all&callback=x')
        ->assertStatus(405);
})->group('RF-PA-01', 'RS-04');

/*
 * SOLO LOS DOS CANALES QUE EXISTEN, ESCRITOS TAL CUAL (bloque 12 de la 2.3.0).
 * El difusor de Pusher normaliza `private-encrypted-` y `presence-` antes de
 * buscar el callback, y el patron de `Broadcast::channel()` no escapa el punto:
 * los tres nombres de abajo llegaban al callback de `presence.all` y se le
 * firmaban a RRHH. Ahora son el mismo `403` que un canal ajeno, sin pasar por el
 * difusor y sin asiento de divulgacion.
 */
it('niega con el 403 de un canal ajeno cualquier nombre que no sea uno de los dos canales', function (string $canal): void {
    escenarioDeCanales();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $ajeno = autorizarCanal(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)), 'presence.all');
    Auth::forgetGuards();
    $asientos = DB::table('audit_log')->count();

    $respuesta = Api::as($token)->post('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal]);

    $respuesta->assertStatus(403)->assertValidResponse(403);

    expect($respuesta->json())->toBe($ajeno->json())
        ->and(DB::table('error_events')->count())->toBe(0)
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->with([
    'cifrado' => ['private-encrypted-presence.all'],
    'presencia de Pusher' => ['presence-presence.all'],
    'punto sin escapar' => ['private-presenceXall'],
])->group('RF-ID-03', 'RS-04', 'RS-05');
