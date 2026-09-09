<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\InMemoryErrorEventSink;

/*
 * Los errores de la tablet suben DENTRO DEL LATIDO (RF-PD-15, tarea 5.12,
 * decision 7).
 *
 * ## Por que en el latido y no en un endpoint propio
 *
 * Porque el quiosco ya tiene un canal que sube cada minuto y una cola de fichajes
 * que no puede competir con nada. Abrir otra conexion en un cambio de turno
 * —cuarenta personas fichando y la red de un hotel— seria gastar en contar lo que
 * pasa el ancho de banda que hace falta para que pase. El panel y el portal, que
 * no tienen esa restriccion, usan `POST /api/v1/client-errors`.
 *
 * ## Que se afirma aqui
 *
 * 1. Que lo que sube se capta, **con el origen y la severidad que pone el
 *    servidor** y con el identificador publico del dispositivo, nunca con lo que
 *    diga el cuerpo.
 * 2. Que el acuse (`client_errors_accepted`) es lo que la tablet necesita para
 *    `acknowledge(n)`: un prefijo, y `0` cuando no se pudo guardar nada.
 * 3. Que **un fallo del historico no tumba el latido** (regla dura 19): la senal
 *    de que la tablet sigue viva es lo ultimo que puede perderse.
 * 4. Que la peticion y la respuesta cumplen `openapi.yaml` (RQ-06, ADR-013): de
 *    ese fichero se genera el cliente de la PWA.
 *
 * El saneado, la huella y la agrupacion son de la otra mitad de la tarea y tienen
 * sus propias pruebas: aqui se comprueba lo que llega al puerto, no lo que la
 * tabla guarda.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un quiosco vinculado y el doble del historico ya enlazado en el contenedor.
 *
 * @return array{token: string, deviceUuid: string, sink: InMemoryErrorEventSink}
 */
function quioscoConHistorico(): array
{
    $escenario = AttendanceFixtures::scenario();
    $sink = new InMemoryErrorEventSink;

    app()->instance(ErrorEventSink::class, $sink);

    return [
        'token' => $escenario['token'],
        'deviceUuid' => $escenario['deviceUuid'],
        'sink' => $sink,
    ];
}

/**
 * Un elemento de `client_errors` **con el contexto que la tablet manda de
 * verdad**.
 *
 * Las claves no estan inventadas: son las que emite
 * `frontend-kiosk/src/shared/telemetry/errorReporter.ts` y sus llamantes
 * (`error_type`, `message`, `scope`, `cause`, `reason`, `skew_seconds`…). Una
 * prueba que enviara un contexto de fantasia comprobaria un camino que ningun
 * quiosco recorre, y fue justo lo que dejo pasar que `message` -la clave que
 * lleva el texto del error- no se estuviera elevando a la columna `message`: sin
 * ella, todos los `kiosk.unhandled_error` de una instalacion colapsaban en una
 * sola fila del panel.
 *
 * El contexto por defecto no va vacio a proposito: un `[]` de PHP se serializa
 * como array JSON y no como objeto, y `assertValidRequest()` lo rechazaria por
 * una cuestion de codificacion del cliente de pruebas y no del producto. Que la
 * tablet pueda mandar `context: {}` lo dice el contrato y lo cubre la regla
 * `present` del `FormRequest`.
 *
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
function errorDeCliente(
    string $code,
    string $occurredAt = '2026-09-09T05:58:31Z',
    array $context = ['cause' => 'NotReadableError'],
): array {
    return [
        'code' => $code,
        'occurred_at' => $occurredAt,
        'app_version' => '2.2.0',
        'context' => $context,
    ];
}

/**
 * @param  array<array-key, mixed>|null  $clientErrors  `null` es «el latido no trae el campo».
 * @return TestResponse<Response>
 */
function latido(string $token, ?array $clientErrors = null): TestResponse
{
    $body = ['app_version' => '2.2.0', 'pending_queue_size' => 0];

    if ($clientErrors !== null) {
        $body['client_errors'] = $clientErrors;
    }

    return Api::as($token)->post('/api/v1/kiosk/heartbeat', $body);
}

it('capta los errores que el quiosco adjunta al latido y los acusa', function (): void {
    $quiosco = quioscoConHistorico();
    $sink = $quiosco['sink'];

    $respuesta = latido($quiosco['token'], [
        // Critico: sin camara no se puede fichar (regla dura 19, decision 3). El
        // contexto es el que manda `ScanView.vue` de verdad.
        errorDeCliente('kiosk.camera.stream_lost', context: [
            'error_type' => 'NotReadableError',
            'message' => 'Could not start video source',
            'scope' => 'scanner',
        ]),
        // Corriente: el vigilante reinicio el escaner y siguio funcionando.
        errorDeCliente('kiosk.scanner.watchdog_restart', '2026-09-09T05:58:40Z'),
    ]);

    $respuesta->assertOk()
        ->assertValidRequest()
        ->assertValidResponse()
        ->assertJsonPath('client_errors_accepted', 2);

    expect($sink->reports)->toHaveCount(2)
        // El origen lo decide el TIPO DE TOKEN, no el cuerpo: aqui llego un
        // dispositivo, luego es `kiosk` (decision 7).
        ->and($sink->at(0)->source)->toBe(ErrorSource::Kiosk)
        ->and($sink->at(1)->source)->toBe(ErrorSource::Kiosk)
        // La severidad sale de la tabla de `ErrorLevel`, no del cliente.
        ->and($sink->at(0)->level)->toBe(ErrorLevel::Critical)
        ->and($sink->at(1)->level)->toBe(ErrorLevel::Error)
        // El identificador PUBLICO del quiosco, y del token (regla dura 21).
        ->and($sink->at(0)->deviceId)->toBe($quiosco['deviceUuid'])
        ->and($sink->at(1)->deviceId)->toBe($quiosco['deviceUuid'])
        ->and($sink->at(0)->code)->toBe('kiosk.camera.stream_lost')
        ->and($sink->at(0)->appVersion)->toBe('2.2.0')
        ->and($sink->at(0)->occurredAt->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-09T05:58:31Z')
        // `message` SE ELEVA a la columna y SE RETIRA del contexto: es la clave
        // canonica que comparten los tres reporters y `/client-errors`.
        ->and($sink->at(0)->message)->toBe('Could not start video source')
        ->and($sink->at(0)->context)->toBe(['error_type' => 'NotReadableError', 'scope' => 'scanner'])
        // Sin mensaje propio, el codigo: una fila del panel sin nada escrito no
        // le dice nada a quien la lee.
        ->and($sink->at(1)->message)->toBe('kiosk.scanner.watchdog_restart')
        // Nada de esto lleva nombre, correo ni identificador secuencial.
        ->and($sink->at(0)->employeeUuid)->toBeNull();
})->group('RF-PD-15', 'RF-PA-07');

it('eleva el mensaje del contexto y no lo deja repetido dentro', function (): void {
    // Repetirlo gastaria una de las doce claves permitidas, ocuparia dos veces el
    // mismo dato y obligaria a sanearlo con dos reglas distintas: la columna
    // `message` admite 1 000 caracteres y un valor de contexto 200.
    $quiosco = quioscoConHistorico();

    latido($quiosco['token'], [
        errorDeCliente('kiosk.offline.storage_unavailable', context: [
            'message' => 'QuotaExceededError',
            'reason' => 'indexeddb',
        ]),
    ])->assertOk();

    $error = $quiosco['sink']->only();

    expect($error->message)->toBe('QuotaExceededError')
        ->and($error->context)->toBe(['reason' => 'indexeddb'])
        ->and($error->context)->not->toHaveKey('message');
})->group('RF-PD-15');

it('clasifica con el origen delante y no solo con el codigo', function (): void {
    // Revision de seguridad (decision 3): `forClientCode()` recibe el ORIGEN. Un
    // codigo de camara que llega por un token de quiosco es `critical`; el mismo
    // codigo por una sesion de portal ni siquiera es un codigo valido, y eso lo
    // prueba `ClientErrorCatalogTest` sobre el catalogo.
    $quiosco = quioscoConHistorico();

    latido($quiosco['token'], [
        errorDeCliente('kiosk.pin.seal_failed'),
        errorDeCliente('kiosk.clock.skew_detected', context: ['skew_seconds' => 412]),
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    expect($quiosco['sink']->at(0)->level)->toBe(ErrorLevel::Critical)
        ->and($quiosco['sink']->at(1)->level)->toBe(ErrorLevel::Error)
        ->and($quiosco['sink']->at(1)->context)->toBe(['skew_seconds' => 412]);
})->group('RF-PD-15');

it('acusa cero cuando el latido no trae errores', function (): void {
    // El campo obligatorio de la RESPUESTA con la lista ausente en la PETICION:
    // es el caso normal —la inmensa mayoria de los latidos— y el que fija que
    // `client_errors_accepted` va siempre.
    $quiosco = quioscoConHistorico();

    latido($quiosco['token'])
        ->assertOk()
        ->assertValidRequest()
        ->assertValidResponse()
        ->assertJsonPath('client_errors_accepted', 0);

    expect($quiosco['sink']->isEmpty())->toBeTrue();
})->group('RF-PD-15', 'RF-PA-07');

it('sigue respondiendo al latido aunque el historico no pueda guardar nada', function (): void {
    // Regla dura 19. La base de datos del historico no responde, el puerto
    // devuelve `0` sin lanzar y la tablet **conserva su buffer**: los errores
    // vuelven en el latido siguiente y el fichaje no se entera de nada.
    $quiosco = quioscoConHistorico();
    $quiosco['sink']->acceptUpTo(0);

    latido($quiosco['token'], [errorDeCliente('kiosk.camera.unavailable')])
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('client_errors_accepted', 0);
})->group('RF-PD-15', 'RF-KI-03');

it('acusa solo el prefijo que el historico pudo guardar', function (): void {
    // `acknowledge(n)` vacia los `n` MAS ANTIGUOS: por eso el recuento tiene que
    // ser un prefijo y no un total de aciertos sueltos.
    $quiosco = quioscoConHistorico();
    $quiosco['sink']->acceptUpTo(1);

    latido($quiosco['token'], [
        errorDeCliente('kiosk.camera.stream_lost'),
        errorDeCliente('kiosk.scanner.watchdog_restart'),
    ])
        ->assertOk()
        ->assertJsonPath('client_errors_accepted', 1);

    expect($quiosco['sink']->only()->code)->toBe('kiosk.camera.stream_lost');
})->group('RF-PD-15');

it('rechaza con 400 un lote de errores que no cumple el contrato', function (array $clientErrors): void {
    $quiosco = quioscoConHistorico();

    latido($quiosco['token'], $clientErrors)
        ->assertStatus(400)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-request');

    // Y no se capta nada: un lote invalido no se guarda «lo que se pueda».
    expect($quiosco['sink']->isEmpty())->toBeTrue();
})->with([
    // El techo de 50 del contrato. Sin el, una tablet con un bucle de errores
    // podria escribir sin limite en la tabla que viaja al fabricante.
    'cincuenta y uno' => [array_fill(0, 51, errorDeCliente('kiosk.camera.stream_lost'))],
    // Un codigo libre acabaria siendo una frase, y una frase acaba llevando un
    // nombre (regla dura 21).
    'codigo malformado' => [[errorDeCliente('Camara rota de Maria')]],
    // Y desde la revision de seguridad tampoco vale un codigo BIEN FORMADO que no
    // este en el catalogo del quiosco: cada uno nuevo era una huella y una fila
    // nuevas, 600 por minuto y actor, durante noventa dias (decision 14).
    'codigo fuera del catalogo' => [[errorDeCliente('kiosk.camera.exploded')]],
    // El cruce de origenes: un token de quiosco tampoco puede reportar como si
    // fuera el panel. Cada origen tiene su catalogo y solo el suyo.
    'codigo de otro origen' => [[errorDeCliente('web.vue_error')]],
    // Regla dura 3: el instante va en UTC con sufijo Z.
    'instante sin Z' => [[errorDeCliente('kiosk.camera.stream_lost', '2026-09-09T07:58:31+02:00')]],
    // El `additionalProperties: false` del contrato: un `stack` no viaja nunca,
    // porque una URL con un uuid dentro correlaciona a una persona.
    'campo de mas' => [[[...errorDeCliente('kiosk.camera.stream_lost'), 'stack' => 'at foo()']]],
    // El `oneOf` de escalares: una estructura anidada es la via por la que se
    // cuela una fila de plantilla.
    'contexto anidado' => [[errorDeCliente('kiosk.camera.stream_lost', context: ['employee' => ['name' => 'Maria']])]],
])->group('RF-PD-15', 'RL-19');
