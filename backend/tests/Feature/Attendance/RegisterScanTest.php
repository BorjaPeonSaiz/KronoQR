<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/scan` de punta a punta: el endpoint completo, validado contra
 * `docs/api/openapi.yaml` en cada respuesta.
 *
 * Cubre los Gherkin del doc 01 §11 que esta tarea tiene que satisfacer:
 * *Primer fichaje de la jornada*, *Cierre de turno con acumulado*,
 * *Anti-rebote*, *Idempotencia ante reintento*, *QR falsificado* y el lado
 * servidor de *Fichaje offline y sincronizacion posterior*.
 *
 * **El reloj esta detenido** (regla dura 2, ADR-021). Sin eso, `recorded_at` y
 * el desfase de reloj de RF-AT-10 cambiarian en cada ejecucion y la mitad de
 * estas aserciones serian imposibles de escribir.
 *
 * **La credencial la resuelve un doble.** Lo que se comprueba aqui es que un
 * escaneo abre o cierra un tramo, no que el HMAC verifique: eso es del
 * verificador de la tarea 1.5 y tiene sus propias pruebas. Es exactamente para
 * lo que `CredentialResolver` es un puerto (ADR-025).
 */

uses(RefreshDatabase::class);

const TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const TARJETA_FALSA = 'FH1.a3.0000000000000000000000.0000000000000000';

/**
 * El «ahora» de este fichero.
 *
 * `AHORA_DEL_FICHAJE` y no `AHORA` a secas porque **las constantes de un fichero
 * de Pest son globales**: `tests/Unit/Kiosk/Domain/PairingRequestTest.php`
 * declaraba tambien `AHORA`, y en cuanto los dos ficheros entran en el mismo
 * proceso —cualquier filtro que cruce suites, como `php artisan test
 * --group=RF-AT-12`— la segunda declaracion aborta el arranque con un
 * `ErrorException` que no dice de donde viene.
 */
const AHORA_DEL_FICHAJE = '2026-03-14 07:02:31';

/**
 * El escenario completo, con el reloj detenido y la credencial ya declarada.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, metrics: RecordingScanMetrics}
 */
function escenarioDeFichaje(string $ahora = AHORA_DEL_FICHAJE, string $timezone = 'Europe/Madrid'): array
{
    $escenario = AttendanceFixtures::scenario($timezone);

    FrozenTime::at($ahora);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(TARJETA, $escenario['employee'])
            ->rejecting(TARJETA_FALSA, CredentialRejectionReason::INVALID_SIGNATURE),
    );

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    return [...$escenario, 'metrics' => $metricas];
}

/**
 * Un escaneo tal y como lo envia el quiosco: la cabecera `Idempotency-Key`
 * coincide con el `scan_id` del cuerpo, como exige el contrato.
 *
 * @param  array{token: string, ...}  $escenario
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function escanear(array $escenario, string $scanId, string $occurredAt = '2026-03-14T07:02:31Z', array $overrides = []): TestResponse
{
    $cuerpo = array_merge([
        'scan_id' => $scanId,
        'occurred_at' => $occurredAt,
        'qr_payload' => TARJETA,
    ], $overrides);

    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', $cuerpo);
}

// --- El camino normal --------------------------------------------------------

it('abre un tramo en el primer fichaje de la jornada', function (): void {
    // Gherkin «Primer fichaje de la jornada»: se crea un tramo con entrada y sin
    // salida, y el quiosco muestra el nombre para poder decir «Buenos dias, ...».
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = escanear($escenario, $scanId);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and($respuesta->json('scan_id'))->toBe($scanId)
        ->and($respuesta->json('work_date'))->toBe('2026-03-14')
        ->and($respuesta->json('worked_minutes'))->toBe(0)
        // El nombre en su forma minima (§7.3): un token de quiosco robado no
        // debe reconstruir la plantilla del hotel.
        ->and($respuesta->json('employee_display_name'))->toBe('Persona D.');

    $tramo = DB::table('shift_entries')->first();

    expect($tramo?->status)->toBe('open')
        ->and($tramo?->clocked_out_at)->toBeNull()
        ->and($tramo?->site_id)->toBe($escenario['site']);
})->group('RF-AT-01', 'RF-AT-02', 'RF-AT-05');

it('cierra el turno y devuelve el acumulado recalculado del dia', function (): void {
    // Gherkin «Cierre de turno con acumulado»: 120 minutos previos + 240 = 360,
    // y el quiosco enseña «Hoy: 6 h 0 min».
    $escenario = escenarioDeFichaje('2026-03-14 05:00:00');
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);

    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $escenario['site'],
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 03:00:00+00',
        'clocked_out_at' => '2026-03-14 05:00:00+00',
        'duration_minutes' => 120,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
    ]);

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:02:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:02:00');
    $salida = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:02:00Z');

    $salida->assertOk()->assertValidResponse();

    expect($salida->json('action'))->toBe('clock_out')
        ->and($salida->json('worked_minutes'))->toBe(360);

    // Y la proyeccion cuadra con sus eventos origen (RN-06, regla dura 7).
    $total = DB::table('daily_totals')->where('employee_id', $employeeId)->first();

    expect($total?->total_minutes)->toBe(360)
        ->and($total?->shift_count)->toBe(2)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-03', 'RN-06');

// --- Anti-rebote -------------------------------------------------------------

it('descarta el segundo escaneo dentro del periodo de gracia', function (): void {
    // Gherkin «Anti-rebote». Es un `200` con `action: debounced` y no un error
    // (ADR-031): la cola offline reintenta ante fallo, y un `4xx` la dejaria
    // reintentando contra una ventana que ya paso.
    $escenario = escenarioDeFichaje();

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:02:31Z')->assertOk();

    FrozenTime::at('2026-03-14 07:02:51');
    $rebote = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:02:51Z');

    $rebote->assertOk()->assertValidResponse();

    expect($rebote->json('action'))->toBe('debounced')
        // Sin `work_date`: no se creo ningun tramo que atribuir a una jornada.
        ->and($rebote->json('work_date'))->toBeNull()
        // Con `last_accepted_at`, que es lo que permite decir «hace unos
        // segundos» sin inventarselo.
        ->and($rebote->json('last_accepted_at'))->toBe('2026-03-14T07:02:31.000Z')
        ->and($rebote->json('employee_display_name'))->toBe('Persona D.');

    // Un solo tramo, y el escaneo queda registrado con su desenlace real.
    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('result', 'rejected_debounce')->count())->toBe(1)
        ->and($escenario['metrics']->countOf(ScanResult::REJECTED_DEBOUNCE))->toBe(1);
})->group('RF-AT-06');

it('deja fichar la salida pasada la ventana de gracia', function (): void {
    // La otra mitad de RF-AT-06: el anti-rebote no puede impedir que alguien
    // fiche la salida. Con la ventana de serie en 60 s, a los 61 s ya se puede.
    $escenario = escenarioDeFichaje();

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:02:31Z')->assertOk();

    FrozenTime::at('2026-03-14 07:03:31');
    $salida = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:03:31Z');

    expect($salida->json('action'))->toBe('clock_out');
})->group('RF-AT-06', 'RF-AT-03');

// --- Idempotencia ------------------------------------------------------------

it('devuelve la respuesta original ante un reenvio con el mismo scan_id', function (): void {
    // Gherkin «Idempotencia ante reintento»: no se crea un segundo tramo y la
    // respuesta es identica, incluida `recorded_at`, que es la de la peticion
    // ORIGINAL y no la del reenvio.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $primera = escanear($escenario, $scanId);
    $primera->assertOk();

    // El reenvio llega mas tarde, como llegaria de la cola offline.
    FrozenTime::at('2026-03-14 09:30:00');
    $reenvio = escanear($escenario, $scanId);

    $reenvio->assertOk()->assertValidResponse();

    expect($reenvio->json())->toBe($primera->json())
        ->and($reenvio->json('action'))->toBe('clock_in')
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(1);
})->group('RF-AT-07', 'RQ-03');

it('distingue un reenvio de un escaneo nuevo dentro de la ventana', function (): void {
    // No son lo mismo y el contrato lo dice: el reenvio es el MISMO escaneo que
    // vuelve —devuelve `clock_in`—, y el anti-rebote es un escaneo NUEVO, con
    // otro `scan_id`, porque la persona paso la tarjeta dos veces.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    escanear($escenario, $scanId)->assertOk();

    expect(escanear($escenario, $scanId)->json('action'))->toBe('clock_in')
        ->and(escanear($escenario, Str::uuid7()->toString())->json('action'))->toBe('debounced');
})->group('RF-AT-07', 'RF-AT-06');

// --- Rechazos ----------------------------------------------------------------

it('rechaza un QR falsificado con una respuesta generica', function (): void {
    // Gherkin «QR falsificado»: error generico sin indicar la causa, el intento
    // queda con `rejected_signature` y se incrementa el contador.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = escanear($escenario, $scanId, overrides: ['qr_payload' => TARJETA_FALSA]);

    $respuesta->assertStatus(422)->assertValidResponse();

    expect($respuesta->json('type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and($respuesta->json('detail'))->toBe('El escaneo no se ha podido registrar.')
        ->and($respuesta->json('scan_id'))->toBe($scanId)
        // El cuerpo no tiene ningun hueco donde alojar la causa.
        ->and(array_keys((array) $respuesta->json()))->toBe(['type', 'title', 'status', 'detail', 'scan_id']);

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('rejected_signature')
        ->and(DB::table('shift_entries')->count())->toBe(0)
        ->and($escenario['metrics']->countOf(ScanResult::REJECTED_SIGNATURE))->toBe(1);
})->group('RF-QR-02', 'RS-03');

it('hace indistinguibles el codigo desconocido, el revocado y el empleado de baja', function (): void {
    // Regla dura 17 y doc 02 §5.2 punto 6: una sola respuesta para todas las
    // causas. Si el cuerpo variase, el endpoint seria un oraculo con el que
    // sondear que credenciales hay emitidas.
    $escenario = escenarioDeFichaje();
    $deBaja = WorkforceFixtures::employee($escenario['site'], null, 'terminated');

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->rejecting('desconocida', CredentialRejectionReason::UNKNOWN)
            ->rejecting('revocada', CredentialRejectionReason::REVOKED)
            ->rejecting('mal-firmada', CredentialRejectionReason::INVALID_SIGNATURE)
            ->resolving('de-baja', $deBaja),
    );

    $cuerpos = [];
    $resultados = [];

    foreach (['desconocida', 'revocada', 'mal-firmada', 'de-baja'] as $payload) {
        $scanId = Str::uuid7()->toString();
        $respuesta = escanear($escenario, $scanId, overrides: ['qr_payload' => $payload]);

        $respuesta->assertStatus(422);

        // Se compara el cuerpo SIN el `scan_id`, que es un eco de lo que envio
        // el cliente y no dice nada que el cliente no supiera ya.
        $cuerpo = (array) $respuesta->json();
        unset($cuerpo['scan_id']);
        $cuerpos[] = $cuerpo;

        $resultados[] = DB::table('scan_events')->where('scan_id', $scanId)->value('result');
    }

    expect(array_unique(array_map(json_encode(...), $cuerpos)))->toHaveCount(1)
        // Y la causa concreta si existe, del lado del servidor: es lo que
        // `scans_total{device,result}` necesita distinguir (doc 02 §8.2).
        ->and($resultados)->toBe([
            'rejected_unknown',
            'rejected_revoked',
            'rejected_signature',
            'rejected_unknown',
        ]);
})->group('RS-03', 'RN-14', 'RF-QR-03');

// --- Las dos marcas de tiempo -----------------------------------------------

it('respeta el occurred_at de un escaneo sincronizado once horas despues', function (): void {
    // Gherkin «Fichaje offline y sincronizacion posterior», lado servidor: el
    // tramo refleja la entrada de las 08:00 aunque llegue a las 19:00, y el
    // desfase queda registrado sin que nadie rechace nada (regla dura 9 y 19).
    $escenario = escenarioDeFichaje('2026-03-14 19:00:00');
    $scanId = Str::uuid7()->toString();

    $respuesta = escanear($escenario, $scanId, '2026-03-14T08:00:00Z');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('occurred_at'))->toBe('2026-03-14T08:00:00.000Z')
        ->and($respuesta->json('recorded_at'))->toBe('2026-03-14T19:00:00.000Z');

    $tramo = DB::table('shift_entries')->first();
    $escaneo = DB::table('scan_events')->where('scan_id', $scanId)->first();

    // El registro legal usa `occurred_at` (RF-AT-09).
    expect(substr((string) $tramo?->clocked_in_at, 0, 19))->toBe('2026-03-14 08:00:00')
        // RF-AT-10: el desfase se persiste, con signo, y el fichaje se acepta.
        ->and($escaneo?->clock_skew_seconds)->toBe(11 * 3600)
        ->and($escaneo?->result)->toBe('clock_in')
        // RN-15, segunda frase: «si supera el umbral, requiere validacion del
        // responsable». Once horas pasan de los 15 minutos de serie, asi que el
        // tramo entra en el registro legal **y** marcado.
        ->and($escaneo?->flagged_for_review)->toBeTrue();
})->group('RF-AT-09', 'RF-AT-10', 'RN-15');

it('no marca para validacion un escaneo que llega en hora', function (): void {
    // El contrapunto de la prueba anterior: si toda la plantilla apareciera
    // marcada, la marca no distinguiria nada y la bandeja de la 3.5 naceria
    // inservible.
    $escenario = escenarioDeFichaje('2026-03-14 07:00:10');
    $scanId = Str::uuid7()->toString();

    escanear($escenario, $scanId, '2026-03-14T07:00:00Z')->assertOk();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('flagged_for_review'))->toBeFalse();
})->group('RN-15');

it('acepta el fichaje aunque el reloj del quiosco vaya adelantado', function (): void {
    // Gherkin «Reloj del quiosco desviado»: 40 minutos de adelanto y el fichaje
    // **se registra igualmente** (regla dura 19). El desfase queda en negativo,
    // que es lo que distingue un reloj adelantado de uno atrasado.
    $escenario = escenarioDeFichaje('2026-03-14 07:00:00');

    $respuesta = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:40:00Z');

    $respuesta->assertOk();

    expect(DB::table('scan_events')->value('clock_skew_seconds'))->toBe(-2400)
        ->and(DB::table('shift_entries')->count())->toBe(1)
        // Marcado, no rechazado: el adelanto tambien supera el umbral, y la
        // marca es lo que distingue «aceptado y verificado» de «aceptado porque
        // el empleado no tiene la culpa del reloj» (RN-15).
        ->and(DB::table('scan_events')->value('flagged_for_review'))->toBeTrue();
})->group('RF-AT-10', 'RN-15');

// --- Turno de noche ----------------------------------------------------------

it('cierra a las 06:00 el turno que entro a las 22:00 sin partirlo', function (): void {
    // RN-05, ADR-006 y regla dura 4. `work_date` es el dia 13, no el 14: el
    // turno se atribuye a la jornada de su hora de inicio.
    $escenario = escenarioDeFichaje('2026-03-13 21:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-13T21:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 05:00:00');
    $salida = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T05:00:00Z');

    $salida->assertOk()->assertValidResponse();

    expect($salida->json('action'))->toBe('clock_out')
        ->and($salida->json('work_date'))->toBe('2026-03-13')
        ->and($salida->json('worked_minutes'))->toBe(480)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-08', 'RN-05');

// --- Auditoria ---------------------------------------------------------------

it('deja en audit_log una entrada encadenada por cada tramo, sin nombres', function (): void {
    // RL-01 y regla dura 6. La escritura ocurre en la misma transaccion que el
    // fichaje: si el asiento fallara, el fichaje no se confirmaria (ADR-027).
    $escenario = escenarioDeFichaje();

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:02:31Z')->assertOk();

    FrozenTime::at('2026-03-14 11:02:31');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T11:02:31Z')->assertOk();

    /** @var list<stdClass> $asientos */
    $asientos = DB::table('audit_log')->orderBy('id')->get()->all();

    expect($asientos)->toHaveCount(2);

    [$entrada, $salida] = $asientos;

    expect($entrada->action)->toBe('shift_entry.created')
        ->and($salida->action)->toBe('shift_entry.closed')
        // El actor es el quiosco, identificado por su fila de `devices`.
        ->and($entrada->actor_type)->toBe('device')
        ->and($entrada->actor_id)->toBe($escenario['device'])
        ->and($entrada->subject_type)->toBe('shift_entry')
        // Encadenado desde el primer fichaje (RS-07, ADR-032).
        ->and($salida->prev_hash)->toBe($entrada->hash);

    // Regla dura 21: ni un nombre en el trail. Se identifica por `employee_uuid`.
    foreach ($asientos as $asiento) {
        expect($asiento->payload)->toContain($escenario['employee'])
            ->and($asiento->payload)->not->toContain('Persona')
            ->and($asiento->payload)->not->toContain('De Prueba');
    }
})->group('RL-01', 'RS-07');

// --- Errores de forma --------------------------------------------------------

it('responde 400 y no 422 cuando la peticion no cumple el contrato', function (): void {
    // En este endpoint el `422` esta reservado al rechazo de escaneo. Un campo
    // que falta tiene que ser distinguible de una tarjeta que no vale, porque el
    // quiosco hace cosas opuestas con cada uno (RF-KI-04).
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            // Con desplazamiento explicito en vez de `Z`: la zona horaria no es
            // un dato del cliente (regla dura 3).
            'occurred_at' => '2026-03-14T09:02:31+02:00',
            'qr_payload' => TARJETA,
        ]);

    $respuesta->assertStatus(400)->assertValidResponse();

    expect($respuesta->json('type'))->toBe('urn:kronoqr:problem:invalid-request')
        ->and($respuesta->json('errors.occurred_at.0'))->toBe('El instante debe ir en UTC con sufijo Z.')
        ->and(DB::table('scan_events')->count())->toBe(0);
})->group('RQ-06', 'RN-04');

it('exige que la cabecera Idempotency-Key coincida con el scan_id', function (): void {
    // La cabecera es la convencion que entienden los intermediarios HTTP; la
    // garantia real la da el UNIQUE de `scan_events.scan_id` (regla dura 8).
    // Esta comprobacion solo detecta un cliente mal escrito antes de que su
    // error se confunda con otra cosa.
    $escenario = escenarioDeFichaje();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan', [
            'scan_id' => Str::uuid7()->toString(),
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => TARJETA,
        ]);

    $respuesta->assertStatus(400);

    expect($respuesta->json('errors.Idempotency-Key.0'))
        ->toBe('La cabecera Idempotency-Key debe coincidir con scan_id.');
})->group('RF-AT-07');

it('rechaza un scan_id que no sea UUID v7', function (): void {
    // Regla dura 8 y doc 02 §6: v7 es ordenable temporalmente, lo que mantiene
    // la localidad del indice de `scan_events`. Un v4 aqui es un fallo del
    // cliente, no un caso a tolerar.
    $escenario = escenarioDeFichaje();
    $v4 = (string) Str::uuid();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $v4])
        ->post('/api/v1/scan', [
            'scan_id' => $v4,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => TARJETA,
        ]);

    $respuesta->assertStatus(400);
})->group('RF-AT-07');

it('rechaza campos que el endpoint no conoce en lugar de ignorarlos', function (): void {
    // El contrato declara `additionalProperties: false`. Un cliente escrito a
    // mano que enviara `device_id` se iria convencido de haber elegido el
    // quiosco, y el servidor habria usado el del token.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        // El mensaje sale en el idioma negociado, como el resto de la
        // validacion (`lang/es/validation.php`, clave `unknown_field`).
        ->withHeaders(['Idempotency-Key' => $scanId, 'Accept-Language' => 'es'])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => TARJETA,
            'device_id' => 99,
        ]);

    $respuesta->assertStatus(400);

    expect($respuesta->json('errors.device_id.0'))
        ->toBe('El campo device_id no forma parte de esta petición.');
})->group('RQ-06');

it('registra la intencion tal cual aunque contradiga el estado de la jornada', function (): void {
    // ADR-024 y RF-AT-12, fila «sin tramo abierto + break_start» de la tabla de
    // `ScanIntentPolicy`: no hay nada que cerrar, asi que se abre jornada. La
    // intencion **no se reescribe**: `intent` guarda lo que el quiosco pidio y
    // `result` lo que se decidio (doc 01 §5.5), y no se rechaza ni se marca
    // —regla dura 19—, porque casi siempre es un boton mal pulsado.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = escanear($escenario, $scanId, overrides: ['intent' => 'break_start']);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    $escaneo = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and($escaneo?->intent)->toBe('break_start')
        ->and($escaneo?->result)->toBe('clock_in')
        ->and($escaneo?->flagged_for_review)->toBeFalse();
})->group('RF-AT-12');

// --- RF-AT-12: fichaje de pausa (ADR-024) ------------------------------------

it('cierra el tramo como pausa y devuelve el acumulado del dia', function (): void {
    // La fila que estrena RF-AT-12: con tramo abierto, `break_start` cierra el
    // tramo **sin cerrar la jornada**. El acumulado que se devuelve es el total
    // del dia tras el escaneo, igual que en una salida: el tiempo de la pausa
    // simplemente no esta en ningun tramo.
    $escenario = escenarioDeFichaje('2026-03-14 06:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:00:00');
    $pausa = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:00:00Z', ['intent' => 'break_start']);

    $pausa->assertOk()->assertValidRequest()->assertValidResponse();

    expect($pausa->json('action'))->toBe('break_start')
        ->and($pausa->json('work_date'))->toBe('2026-03-14')
        ->and($pausa->json('worked_minutes'))->toBe(240)
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->value('status'))->toBe('closed')
        // La jornada sigue viva: no hay nada en el agregado que diga «cerrada»,
        // y el escaneo siguiente lo demuestra abriendo otro tramo en ella.
        ->and(DB::table('daily_totals')->where('work_date', '2026-03-14')->value('total_minutes'))->toBe(240);
})->group('RF-AT-12', 'RN-06');

it('vuelve de la pausa sin pulsar nada y no parte el turno de madrugada', function (): void {
    // **La prueba central de ADR-024.** Turno 22:00 -> pausa 02:00 -> vuelta
    // 02:30: la vuelta llega como `auto` —nadie pulsa nada para volver, decision
    // 5 de la ficha— y tiene que continuar la jornada del dia D. Si se atribuyera
    // por la fecha civil del escaneo, el turno quedaria partido en dos jornadas y
    // las horas de la noche se repartirian entre dos dias (RN-05, regla dura 4).
    $escenario = escenarioDeFichaje('2026-03-13 21:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-13T21:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 01:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T01:00:00Z', ['intent' => 'break_start'])
        ->assertOk();

    FrozenTime::at('2026-03-14 01:30:00');
    $vuelta = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T01:30:00Z');

    $vuelta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($vuelta->json('action'))->toBe('break_end')
        ->and($vuelta->json('work_date'))->toBe('2026-03-13')
        // El tramo nuevo esta abierto y aporta cero: el acumulado sigue siendo
        // el de la primera mitad de la noche.
        ->and($vuelta->json('worked_minutes'))->toBe(240)
        ->and(DB::table('shift_entries')->where('work_date', '2026-03-13')->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('work_date', '2026-03-14')->count())->toBe(0)
        // Y la proyeccion del dia siguiente no existe: no se ha trabajado
        // ninguna jornada del 14 (RN-06, regla dura 7).
        ->and(DB::table('daily_totals')->where('work_date', '2026-03-14')->count())->toBe(0)
        ->and(DB::table('daily_totals')->where('work_date', '2026-03-13')->value('total_minutes'))->toBe(240);

    // Y al cerrar a las 06:00 locales, la noche entera cuadra en el dia 13.
    FrozenTime::at('2026-03-14 05:00:00');
    $salida = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T05:00:00Z');

    expect($salida->json('action'))->toBe('clock_out')
        ->and($salida->json('work_date'))->toBe('2026-03-13')
        ->and($salida->json('worked_minutes'))->toBe(450)
        ->and(DB::table('daily_totals')->where('work_date', '2026-03-14')->count())->toBe(0);
})->group('RF-AT-12', 'RN-05', 'RN-06');

it('trata como entrada la vuelta de una pausa que nadie empezo', function (): void {
    // Sin tramo abierto y sin `break_start` detras, un `break_end` no tiene
    // ninguna jornada que continuar: se abre una. La pausa que si existe tiene
    // ademas su propio techo —el descanso minimo entre jornadas de RN-10— que
    // impide que un «Pausa» de anteayer absorba la entrada de hoy; eso lo
    // ejercita «no convierte la entrada de manana en la vuelta de la pausa de
    // hoy», mas abajo.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = escanear($escenario, $scanId, overrides: ['intent' => 'break_end']);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('intent'))->toBe('break_end');
})->group('RF-AT-12');

it('cierra la jornada cuando se declara vuelta de pausa con el tramo abierto', function (): void {
    // La fila mas contraintuitiva de la tabla: `break_end` con tramo abierto
    // contradice el estado, y se resuelve **por la estructura** cerrando el
    // tramo. No se inventa una pausa que nadie declaro: eso dejaria la jornada
    // abierta para siempre sobre una intencion equivocada.
    $escenario = escenarioDeFichaje('2026-03-14 06:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 14:00:00');
    $respuesta = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T14:00:00Z', ['intent' => 'break_end']);

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_out')
        ->and($respuesta->json('worked_minutes'))->toBe(480)
        ->and(DB::table('shift_entries')->value('status'))->toBe('closed');
})->group('RF-AT-12');

it('devuelve la misma accion de pausa ante un reenvio del mismo scan_id', function (): void {
    // Regla dura 8 con `break_start` de por medio: un reenvio desde la cola
    // offline no puede convertirse en una salida, ni escribir un segundo tramo.
    $escenario = escenarioDeFichaje('2026-03-14 06:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:00:00');
    $scanId = Str::uuid7()->toString();

    $primera = escanear($escenario, $scanId, '2026-03-14T10:00:00Z', ['intent' => 'break_start']);
    $segunda = escanear($escenario, $scanId, '2026-03-14T10:00:00Z', ['intent' => 'break_start']);

    $segunda->assertOk()->assertValidResponse();

    expect($segunda->json('action'))->toBe('break_start')
        ->and($segunda->json())->toBe($primera->json())
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-12', 'RF-AT-07', 'RQ-03');

it('registra auto cuando el quiosco no declara ninguna intencion', function (): void {
    // ADR-012: un cliente que no envia `intent` sigue funcionando y la columna
    // queda en `auto`. Es lo que hace el cambio aditivo y compatible con la v1, y
    // lo que permite que una tablet sin actualizar siga fichando.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    escanear($escenario, $scanId)->assertOk()->assertValidRequest();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('intent'))->toBe('auto');
})->group('RF-AT-12');

it('suprime el doble escaneo accidental pero no la correccion de una pausa', function (): void {
    // Decision 4 de la ficha 3.5 y ADR-024. La ventana de 60 s no cambia: lo que
    // cambia es que una intencion EXPLICITA que deshace al vecino inmediato no
    // es un rebote. Un `break_end` a los 20 s de un `break_start` mal pulsado
    // reabre el tramo —se pierden veinte segundos, no cuatro horas—, mientras
    // que un `auto` a los 20 s sigue siendo el doble escaneo que RF-AT-06
    // descarta.
    $escenario = escenarioDeFichaje('2026-03-14 06:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:00:00Z', ['intent' => 'break_start'])
        ->assertOk();

    FrozenTime::at('2026-03-14 10:00:20');
    $rebote = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:00:20Z');

    expect($rebote->json('action'))->toBe('debounced')
        ->and(DB::table('shift_entries')->count())->toBe(1);

    $vuelta = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:00:20Z', ['intent' => 'break_end']);

    $vuelta->assertOk()->assertValidResponse();

    expect($vuelta->json('action'))->toBe('break_end')
        ->and($vuelta->json('work_date'))->toBe('2026-03-14')
        ->and(DB::table('shift_entries')->count())->toBe(2);
})->group('RF-AT-06', 'RF-AT-12');

// --- RS-02: los ejes del limite de tasa --------------------------------------

it('no limita por credencial: la misma tarjeta repetida no agota ninguna cuota', function (): void {
    // ADR-038. Un `429` por credencial seria la unica forma en que este producto
    // puede dejar a una persona concreta sin fichar —bastaria con inundar con su
    // tarjeta— y contradice la regla dura 19. La repeticion de una misma tarjeta
    // la resuelve el periodo de gracia de RF-AT-06 como desenlace ACEPTADO
    // (ADR-031), no con un rechazo.
    $escenario = escenarioDeFichaje();

    for ($i = 0; $i < 6; $i++) {
        escanear($escenario, Str::uuid7()->toString())->assertOk();
    }
})->group('RS-02', 'RF-AT-06');

it('distingue en audit_log la pausa del fin de jornada sobre el mismo tramo', function (): void {
    // RF-AT-12, RL-04 y regla dura 6. `scan_events.result` ya sabia cual fue
    // cada escaneo, pero esa tabla **no es solo-append ni encadenada por hash**:
    // el asiento de `audit_log` es la copia del hecho que no se puede
    // reescribir, y sin `action` afirmaria que alguien termino su jornada a las
    // 10:00 cuando solo se fue a desayunar.
    //
    // Se compara sobre el MISMO tramo, cerrado de las dos formas en dos
    // escenarios equivalentes: si el asiento no llevara la accion, los dos
    // payloads serian identicos byte a byte.
    $escenario = escenarioDeFichaje('2026-03-14 06:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:00:00Z', ['intent' => 'break_start'])
        ->assertOk();

    FrozenTime::at('2026-03-14 10:30:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T10:30:00Z')->assertOk();

    FrozenTime::at('2026-03-14 14:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T14:00:00Z')->assertOk();

    /** @var list<stdClass> $asientos */
    $asientos = DB::table('audit_log')->orderBy('id')->get()->all();

    $acciones = array_map(
        /** @param stdClass $asiento */
        static function (object $asiento): string {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $asiento->payload, true, 512, JSON_THROW_ON_ERROR);

            $accion = $payload['action'] ?? null;

            return $asiento->action.':'.(is_string($accion) ? $accion : 'SIN ACCION');
        },
        $asientos,
    );

    expect($acciones)->toBe([
        'shift_entry.created:clock_in',
        // La pausa cierra el tramo **sin** cerrar la jornada, y el asiento lo
        // dice: no es un `clock_out`.
        'shift_entry.closed:break_start',
        // Y la vuelta abre uno nuevo continuando la misma jornada.
        'shift_entry.created:break_end',
        'shift_entry.closed:clock_out',
    ]);
})->group('RF-AT-12', 'RL-04', 'RL-01');

it('no convierte la entrada de manana en la vuelta de la pausa de hoy', function (): void {
    // **El techo de la pausa es RN-10**, el descanso minimo entre jornadas del
    // perfil del centro (12 h en ES-hosteleria). Sin el, alguien que pulsa
    // «Pausa» a las 15:00 y se va a casa haria que su entrada del dia siguiente
    // se resolviera como vuelta de pausa: las ocho horas del martes cargadas a
    // la jornada del lunes, el martes a cero, y nadie enterandose hasta la
    // nomina.
    $escenario = escenarioDeFichaje('2026-03-14 06:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 14:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T14:00:00Z', ['intent' => 'break_start'])
        ->assertOk();

    // Dos dias despues, la entrada normal del lunes siguiente.
    FrozenTime::at('2026-03-16 06:00:00');
    $entrada = escanear($escenario, Str::uuid7()->toString(), '2026-03-16T06:00:00Z');

    $entrada->assertOk()->assertValidResponse();

    expect($entrada->json('action'))->toBe('clock_in')
        ->and($entrada->json('work_date'))->toBe('2026-03-16')
        // Su jornada empieza a cero, no hereda las ocho horas del sabado.
        ->and($entrada->json('worked_minutes'))->toBe(0)
        // Y la jornada del 14 queda como estaba: corta —la pausa que nadie
        // cerro— pero intacta, y se corrige con RN-13 como cualquier otra.
        ->and(DB::table('daily_totals')->where('work_date', '2026-03-14')->value('total_minutes'))->toBe(480)
        ->and(DB::table('shift_entries')->where('work_date', '2026-03-14')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-12', 'RN-05', 'RN-06', 'RN-10');

it('continua la pausa justo por debajo del descanso minimo y no en el umbral', function (
    string $vuelta,
    string $accion,
    string $jornada,
): void {
    // El limite es **estricto**, como el resto del dominio (`TimeRange`, RN-02,
    // `DebouncePolicy`): con las 12 h de ES-hosteleria, 11 h 59 sigue siendo la
    // misma jornada y 12 h 00 exactas ya son dos. Se prueban los dos lados del
    // umbral porque un `<=` en lugar de un `<` no lo notaria nadie hasta que una
    // jornada entera cambiara de dia.
    //
    // La pausa empieza a las 21:00 locales del 14, asi que la vuelta cae ya en
    // el dia natural siguiente: lo que distingue los dos casos es la jornada a
    // la que se atribuye, que es lo que acaba en la nomina.
    $escenario = escenarioDeFichaje('2026-03-14 12:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T12:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 20:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T20:00:00Z', ['intent' => 'break_start'])
        ->assertOk();

    FrozenTime::at(str_replace(['T', 'Z'], [' ', ''], $vuelta));
    $respuesta = escanear($escenario, Str::uuid7()->toString(), $vuelta);

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe($accion)
        ->and($respuesta->json('work_date'))->toBe($jornada);
})->with([
    // 20:00 + 11 h 59 min: la comida mas larga del mundo, pero la misma jornada.
    '11 h 59 continua la pausa' => ['2026-03-15T07:59:00Z', 'break_end', '2026-03-14'],
    // 20:00 + 12 h 00 min exactas: por definicion legal ya es otro dia de
    // trabajo, asi que se abre jornada nueva.
    '12 h 00 abre jornada' => ['2026-03-15T08:00:00Z', 'clock_in', '2026-03-15'],
])->group('RF-AT-12', 'RN-10', 'RN-05');

it('vuelve de la pausa a la misma jornada aunque RRHH haya corregido el tramo entretanto', function (): void {
    // RF-AT-12 + RN-13 + RN-05 juntos, que es donde estaba el defecto: la vuelta
    // busca la jornada por el `uuid` que el `break_start` dejo escrito, y una
    // correccion durante la pausa deja ese tramo `superseded`. Con la consulta
    // de las correcciones —que solo ve versiones vigentes— la vuelta no lo
    // encontraria, abriria jornada por la fecha civil del escaneo y **partiria
    // el turno de noche en dos dias**.
    //
    // Es un escenario realista, no rebuscado: el turno de noche entra a las
    // 22:00, se va a cenar a las 02:00 y el responsable aprovecha para arreglar
    // la hora de entrada que aquella persona ficho tarde.
    $escenario = escenarioDeFichaje('2026-03-13 21:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-13T21:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 01:00:00');
    escanear($escenario, Str::uuid7()->toString(), '2026-03-14T01:00:00Z', ['intent' => 'break_start'])
        ->assertOk();

    /** @var string $tramo */
    $tramo = DB::table('shift_entries')->orderBy('id')->value('uuid');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_in_at' => '2026-03-13T20:50:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertStatus(200);

    FrozenTime::at('2026-03-14 01:30:00');
    $vuelta = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T01:30:00Z');

    $vuelta->assertOk()->assertValidResponse();

    expect($vuelta->json('action'))->toBe('break_end')
        // La jornada del 13, no la del 14: el turno sigue entero.
        ->and($vuelta->json('work_date'))->toBe('2026-03-13')
        ->and(DB::table('shift_entries')->where('work_date', '2026-03-14')->count())->toBe(0)
        ->and(DB::table('daily_totals')->where('work_date', '2026-03-14')->count())->toBe(0)
        // 20:50 -> 02:00 corregido = 250 min, y el tramo nuevo abierto aporta 0.
        ->and($vuelta->json('worked_minutes'))->toBe(250)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-12', 'RN-13', 'RN-05', 'RF-PA-04');
