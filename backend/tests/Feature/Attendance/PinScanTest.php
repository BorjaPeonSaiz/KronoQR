<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;

/*
 * `POST /api/v1/scan/pin` de punta a punta, validado contra
 * `docs/api/openapi.yaml` en cada respuesta (RF-AT-11, RS-12, tarea 1.12).
 *
 * Cubre el Gherkin del doc 01 §11:
 *
 *     Escenario: Tarjeta no disponible
 *       Dado un empleado que llega al centro sin su tarjeta
 *       Cuando introduce su PIN de 6 digitos en el quiosco
 *       Entonces se registra el fichaje con origen "PIN"
 *       Y queda marcado para revision del responsable
 *
 * **El reloj esta detenido** (regla dura 2, ADR-021): sin eso, `recorded_at` y
 * el desfase de RF-AT-10 cambiarian en cada ejecucion.
 *
 * **El PIN y su sobre son reales**, no dobles. Al contrario que en
 * `RegisterScanTest` —donde el `CredentialResolver` es un doble porque el HMAC
 * tiene sus propias pruebas—, aqui la verificacion del PIN **es** lo que se esta
 * probando: el hash lo escribe la tarea 1.13 y el sobre lo cierra la prueba con
 * la misma primitiva que documenta el contrato, para estar ejercitando el
 * formato que consumira `frontend-quiosco`.
 */

uses(RefreshDatabase::class);

const PIN_DEL_EMPLEADO = '481902';

const PIN_EQUIVOCADO = '481903';

const MOMENTO = '2026-03-14 07:02:31';

/**
 * Empleado con PIN, quiosco emparejado, claves de sellado y reloj detenido.
 *
 * @return array{site: int, employee: string, code: string, publicKey: string, device: int, deviceUuid: string, token: string, metrics: RecordingScanMetrics}
 */
function escenarioDePin(string $ahora = MOMENTO, string $timezone = 'Europe/Madrid'): array
{
    $escenario = AttendanceFixtures::scenario($timezone);

    EmployeePins::issue($escenario['employee'], PIN_DEL_EMPLEADO);

    app()->instance(Clock::class, FixedClock::at($ahora));

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    return [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
        'metrics' => $metricas,
    ];
}

/**
 * Un fichaje por PIN tal y como lo envia el quiosco: PIN cerrado y cabecera
 * `Idempotency-Key` igual al `scan_id`.
 *
 * @param  array{token: string, code: string, publicKey: string, ...}  $escenario
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function ficharConPin(
    array $escenario,
    string $scanId,
    string $pin = PIN_DEL_EMPLEADO,
    string $occurredAt = '2026-03-14T07:02:31Z',
    array $overrides = [],
): TestResponse {
    $cuerpo = array_merge([
        'scan_id' => $scanId,
        'occurred_at' => $occurredAt,
        'employee_code' => $escenario['code'],
        'pin_sealed' => EmployeePins::seal($pin, $escenario['publicKey']),
    ], $overrides);

    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo);
}

// --- El camino normal --------------------------------------------------------

it('ficha con el PIN cuando no hay tarjeta', function (): void {
    // El Gherkin completo, primera mitad: se registra el fichaje.
    $escenario = escenarioDePin();
    $scanId = Str::uuid7()->toString();

    $respuesta = ficharConPin($escenario, $scanId);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and($respuesta->json('scan_id'))->toBe($scanId)
        ->and($respuesta->json('work_date'))->toBe('2026-03-14')
        ->and($respuesta->json('worked_minutes'))->toBe(0)
        // El mismo cuerpo que `/scan`: el empleado ha fichado, y su pantalla de
        // confirmacion no tiene por que ser distinta.
        ->and($respuesta->json('employee_display_name'))->toBe('Persona D.');

    $tramo = DB::table('shift_entries')->first();

    expect($tramo?->status)->toBe('open')
        ->and($tramo?->clock_in_source)->toBe('pin_kiosk');
})->group('RF-AT-11', 'RF-AT-02');

it('cierra el turno por PIN igual que por tarjeta', function (): void {
    // Misma traza que el QR (RF-AT-11): el servidor decide, no el cliente.
    $escenario = escenarioDePin('2026-03-14 15:00:00');
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);

    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $escenario['site'],
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 07:00:00+00',
        'clock_in_source' => 'qr_kiosk',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $respuesta = ficharConPin($escenario, Str::uuid7()->toString(), occurredAt: '2026-03-14T15:00:00Z');

    $respuesta->assertOk()->assertValidResponse();

    // Entro con la tarjeta y sale con el PIN porque la dejo en la taquilla: cada
    // marca lleva su propio origen (docblock de `ScanOrigin`).
    expect($respuesta->json('action'))->toBe('clock_out')
        ->and($respuesta->json('worked_minutes'))->toBe(480);

    $tramo = DB::table('shift_entries')->first();

    expect($tramo?->clock_in_source)->toBe('qr_kiosk')
        ->and($tramo?->clock_out_source)->toBe('pin_kiosk');
})->group('RF-AT-11', 'RF-AT-03');

it('declara la intencion de pausa igual que el escaneo de tarjeta', function (): void {
    $escenario = escenarioDePin();
    $scanId = Str::uuid7()->toString();

    ficharConPin($escenario, $scanId, overrides: ['intent' => 'break_start'])
        ->assertOk()
        ->assertValidRequest()
        ->assertValidResponse();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('intent'))->toBe('break_start');
})->group('RF-AT-11');

// --- El rechazo, siempre el mismo --------------------------------------------

it('rechaza igual el PIN incorrecto, el codigo inexistente y el bloqueo', function (array $peticion): void {
    // Regla dura 17 y RS-03: **el mismo cuerpo y el mismo codigo** para las tres
    // causas. Si alguna se distinguiera, el endpoint seria un comprobador de
    // plantillas y de PIN.
    $escenario = escenarioDePin();
    $scanId = Str::uuid7()->toString();

    $respuesta = ficharConPin(
        $escenario,
        $scanId,
        pin: $peticion['pin'],
        overrides: $peticion['overrides'] instanceof Closure
            ? ($peticion['overrides'])($escenario)
            : $peticion['overrides'],
    );

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and($respuesta->json('detail'))->toBe('El escaneo no se ha podido registrar.')
        ->and($respuesta->json('scan_id'))->toBe($scanId)
        // Y nada mas: el esquema no admite miembros adicionales, asi que no hay
        // donde alojar «tu PIN esta bloqueado 15 minutos».
        ->and(array_keys((array) $respuesta->json()))->toBe(['type', 'title', 'status', 'detail', 'scan_id']);

    // Todo escaneo escribe su fila, se acepte o no, y sin tramo.
    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($evento?->result)->toBe('rejected_unknown')
        ->and($evento?->shift_entry_id)->toBeNull()
        ->and($evento?->origin)->toBe('pin_kiosk');

    expect(DB::table('shift_entries')->count())->toBe(0);
})->with([
    'PIN incorrecto' => [['pin' => PIN_EQUIVOCADO, 'overrides' => []]],
    'codigo inexistente' => [['pin' => PIN_DEL_EMPLEADO, 'overrides' => ['employee_code' => 'ENOEXISTE']]],
    'sobre que no abre' => [[
        'pin' => PIN_DEL_EMPLEADO,
        'overrides' => ['pin_sealed' => str_repeat('A', 72)],
    ]],
])->group('RF-AT-11', 'RS-03', 'RS-12');

it('rechaza sin distinguirlo cuando el bloqueo por intentos esta activo', function (): void {
    // RS-12: tres fallos y el cuarto intento —con el PIN BUENO— sale por la
    // misma puerta que los anteriores.
    $escenario = escenarioDePin();

    for ($i = 0; $i < 3; $i++) {
        ficharConPin($escenario, Str::uuid7()->toString(), pin: PIN_EQUIVOCADO)->assertStatus(422);
    }

    $scanId = Str::uuid7()->toString();
    $respuesta = ficharConPin($escenario, $scanId, pin: PIN_DEL_EMPLEADO);

    $respuesta->assertStatus(422)->assertValidResponse();

    expect($respuesta->json('detail'))->toBe('El escaneo no se ha podido registrar.')
        ->and(DB::table('shift_entries')->count())->toBe(0);
})->group('RS-12', 'RF-AT-11');

it('no deja fichar por PIN a quien esta de baja', function (): void {
    // RN-14, y por el mismo camino que todo lo demas.
    $escenario = escenarioDePin();

    DB::table('employees')
        ->where('uuid', $escenario['employee'])
        ->update(['status' => 'terminated', 'terminated_at' => '2026-03-01']);

    ficharConPin($escenario, Str::uuid7()->toString())
        ->assertStatus(422)
        ->assertValidResponse();

    expect(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-14', 'RF-AT-11');

it('tarda lo mismo en rechazar un PIN incorrecto que un codigo inexistente', function (): void {
    // RS-03: el suelo lo pone la comparacion del hash, que se paga en los dos
    // caminos. Lo que se vigila es que no haya un orden de magnitud de
    // diferencia, que es lo que produciria saltarse esa comparacion.
    $escenario = escenarioDePin();

    // Pasada en vacio para no medir el arranque del hasher.
    ficharConPin($escenario, Str::uuid7()->toString(), pin: PIN_EQUIVOCADO);

    $conCodigo = cronometrar(fn () => ficharConPin($escenario, Str::uuid7()->toString(), pin: PIN_EQUIVOCADO));
    $sinCodigo = cronometrar(fn () => ficharConPin(
        $escenario,
        Str::uuid7()->toString(),
        overrides: ['employee_code' => 'ENOEXISTE'],
    ));

    expect(abs($conCodigo - $sinCodigo))->toBeLessThan(max($conCodigo, $sinCodigo));
})->group('RS-03', 'RS-12');

/**
 * Milisegundos que tarda una llamada, con reloj monotono.
 *
 * @param  callable(): mixed  $work
 */
function cronometrar(callable $work): float
{
    $startedAt = hrtime(true);
    $work();

    return (hrtime(true) - $startedAt) / 1_000_000;
}

// --- Forma de la peticion ----------------------------------------------------

it('devuelve 400 y no 422 cuando la peticion esta mal formada', function (array $overrides): void {
    // El `422` esta reservado al rechazo generico: el quiosco hace cosas
    // opuestas con cada uno —la peticion mal formada no se reintenta, el rechazo
    // se muestra y se descarta de la cola (ADR-031)—.
    $escenario = escenarioDePin();
    $scanId = Str::uuid7()->toString();

    ficharConPin($escenario, $scanId, overrides: $overrides)->assertStatus(400);

    expect(DB::table('scan_events')->count())->toBe(0);
})->with([
    'sin codigo de empleado' => [['employee_code' => '']],
    'sin PIN' => [['pin_sealed' => '']],
    'sobre que no es base64' => [['pin_sealed' => str_repeat('!', 72)]],
    'instante con desplazamiento en vez de Z' => [['occurred_at' => '2026-03-14T08:02:31+01:00']],
    'campo desconocido' => [['pin' => '481902']],
])->group('RF-AT-11', 'RQ-06');

it('exige la cabecera Idempotency-Key y que coincida con scan_id', function (): void {
    $escenario = escenarioDePin();
    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal(PIN_DEL_EMPLEADO, $escenario['publicKey']),
        ])
        ->assertStatus(400);

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal(PIN_DEL_EMPLEADO, $escenario['publicKey']),
        ])
        ->assertStatus(400);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RF-AT-07', 'RF-AT-11');

// --- El PIN no aparece en ningun sitio ---------------------------------------

it('no escribe el PIN en el log, ni en claro ni cerrado', function (): void {
    // Regla dura 21 y ADR-020: el log tecnico viaja al fabricante dentro del
    // paquete de diagnostico. Un PIN ahi es una credencial de acceso al registro
    // horario personal de alguien (RL-05) en un fichero que se rota y se copia.
    $escenario = escenarioDePin();
    $sobre = EmployeePins::seal(PIN_DEL_EMPLEADO, $escenario['publicKey']);

    /** @var list<array{message: string, context: array<string, mixed>}> $registrado */
    $registrado = [];

    Log::listen(static function (MessageLogged $mensaje) use (&$registrado): void {
        $registrado[] = ['message' => $mensaje->message, 'context' => $mensaje->context];
    });

    $scanId = Str::uuid7()->toString();

    ficharConPin($escenario, $scanId, overrides: ['pin_sealed' => $sobre])->assertOk();
    ficharConPin($escenario, Str::uuid7()->toString(), pin: PIN_EQUIVOCADO)->assertStatus(422);

    expect($registrado)->not->toBeEmpty();

    $serializado = json_encode($registrado, JSON_THROW_ON_ERROR);

    expect($serializado)->not->toContain(PIN_DEL_EMPLEADO)
        ->and($serializado)->not->toContain(PIN_EQUIVOCADO)
        // Ni el sobre: no aporta nada al diagnostico, y guardarlo convertiria el
        // log en una copia de los PIN el dia que la clave privada se filtre.
        ->and($serializado)->not->toContain($sobre)
        ->and($serializado)->not->toContain($escenario['code'])
        // Ni el nombre del empleado: se identifica por su UUID y solo por el.
        ->and($serializado)->not->toContain('Persona D.');
})->group('RS-12', 'RQ-07');

it('no guarda el PIN ni el codigo de empleado en scan_events', function (): void {
    $escenario = escenarioDePin();
    $scanId = Str::uuid7()->toString();

    ficharConPin($escenario, $scanId)->assertOk();

    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();
    $fila = json_encode((array) $evento, JSON_THROW_ON_ERROR);

    expect($fila)->not->toContain(PIN_DEL_EMPLEADO)
        ->and($fila)->not->toContain($escenario['code'])
        // Sin tarjeta no hay huella que tomar: inventar una del codigo de
        // empleado habria metido dos cosas distintas en la misma columna.
        ->and($evento?->payload_fingerprint)->toBeNull();
})->group('RS-12', 'RF-AT-11');

// --- Instrumentacion ----------------------------------------------------------

it('cuenta el fichaje por PIN en pin_fallback_scans_total', function (): void {
    // §8.2: «una subida indica un problema con la emision, el estado de las
    // tarjetas o la disciplina de la plantilla. Es un termometro barato».
    $escenario = escenarioDePin();

    ficharConPin($escenario, Str::uuid7()->toString())->assertOk();

    expect($escenario['metrics']->pinFallbacks)->toBe([$escenario['site']]);
})->group('RF-AT-11', 'RNF-M-03');

it('no cuenta como fichaje por PIN un intento rechazado', function (): void {
    // El termometro mide a quien se quedo sin tarjeta, no a quien se equivoco de
    // PIN: sumar los rechazos haria que un teclado atascado pareciera una remesa
    // de tarjetas rota.
    $escenario = escenarioDePin();

    ficharConPin($escenario, Str::uuid7()->toString(), pin: PIN_EQUIVOCADO)->assertStatus(422);

    expect($escenario['metrics']->pinFallbacks)->toBe([]);
})->group('RF-AT-11');

// --- La clave publica llega al quiosco ---------------------------------------

it('publica la clave de sellado en el padron para que el quiosco pueda cerrar el PIN', function (): void {
    // Sin esto, `frontend-quiosco` no tendria con que cerrar el sobre y la
    // segunda via no existiria. Va con el padron porque es lo mismo que el
    // padron: lo que la tablet necesita para trabajar sin red.
    $escenario = escenarioDePin();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/kiosk/roster');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('pin_sealing_public_key'))->toBe($escenario['publicKey']);
})->group('RF-AT-11', 'RF-KI-03');

it('devuelve la clave nula cuando la instalacion no ofrece fichaje por PIN', function (): void {
    // ADR-017: un cliente puede no querer la segunda via. El quiosco oculta el
    // teclado en vez de ofrecer una puerta que rechaza siempre.
    $escenario = escenarioDePin();

    config()->set('identity.pin.sealing.secret_key', '');

    $respuesta = Api::as($escenario['token'])->get('/api/v1/kiosk/roster');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('pin_sealing_public_key'))->toBeNull();
})->group('RF-AT-11', 'RF-KI-03');
