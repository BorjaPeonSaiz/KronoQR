<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
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
use Tests\Support\Time\FixedClock;
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

const AHORA = '2026-03-14 07:02:31';

/**
 * El escenario completo, con el reloj detenido y la credencial ya declarada.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, metrics: RecordingScanMetrics}
 */
function escenarioDeFichaje(string $ahora = AHORA, string $timezone = 'Europe/Madrid'): array
{
    $escenario = AttendanceFixtures::scenario($timezone);

    app()->instance(Clock::class, FixedClock::at($ahora));
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

    app()->instance(Clock::class, FixedClock::at('2026-03-14 10:02:00'));
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

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:51'));
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

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:03:31'));
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
    app()->instance(Clock::class, FixedClock::at('2026-03-14 09:30:00'));
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
        ->and($escaneo?->result)->toBe('clock_in');
})->group('RF-AT-09', 'RF-AT-10', 'RN-15');

it('acepta el fichaje aunque el reloj del quiosco vaya adelantado', function (): void {
    // Gherkin «Reloj del quiosco desviado»: 40 minutos de adelanto y el fichaje
    // **se registra igualmente** (regla dura 19). El desfase queda en negativo,
    // que es lo que distingue un reloj adelantado de uno atrasado.
    $escenario = escenarioDeFichaje('2026-03-14 07:00:00');

    $respuesta = escanear($escenario, Str::uuid7()->toString(), '2026-03-14T07:40:00Z');

    $respuesta->assertOk();

    expect(DB::table('scan_events')->value('clock_skew_seconds'))->toBe(-2400)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-10');

// --- Turno de noche ----------------------------------------------------------

it('cierra a las 06:00 el turno que entro a las 22:00 sin partirlo', function (): void {
    // RN-05, ADR-006 y regla dura 4. `work_date` es el dia 13, no el 14: el
    // turno se atribuye a la jornada de su hora de inicio.
    $escenario = escenarioDeFichaje('2026-03-13 21:00:00');

    escanear($escenario, Str::uuid7()->toString(), '2026-03-13T21:00:00Z')->assertOk();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 05:00:00'));
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

    app()->instance(Clock::class, FixedClock::at('2026-03-14 11:02:31'));
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
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => TARJETA,
            'device_id' => 99,
        ]);

    $respuesta->assertStatus(400);

    expect($respuesta->json('errors.device_id.0'))
        ->toBe('El campo device_id no forma parte de esta peticion.');
})->group('RQ-06');

it('acepta la intencion declarada y la registra sin interpretarla', function (): void {
    // ADR-024 y RF-AT-12: el fichaje de pausa es de la tarea 3.5. Hasta
    // entonces la intencion se **registra** —la columna existe desde la 1.3
    // porque cambiar el esquema de la cola offline con fichajes pendientes es
    // migrar registro legal sin escribir— y el servidor sigue deduciendo la
    // accion por el estado de la jornada.
    $escenario = escenarioDeFichaje();
    $scanId = Str::uuid7()->toString();

    $respuesta = escanear($escenario, $scanId, overrides: ['intent' => 'break_start']);

    $respuesta->assertOk()->assertValidRequest();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('intent'))->toBe('break_start');
})->group('RF-AT-12');
