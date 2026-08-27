<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;

/*
 * **Idempotencia del fichaje por PIN, incluida bajo concurrencia** (regla dura
 * 8, RF-AT-07, doc 02 §9.4 y §9.5, tarea 1.12).
 *
 * El fichaje por PIN es una escritura del quiosco como cualquier otra: sale de
 * la misma cola offline, se reintenta ante fallo de red y llega varias veces y a
 * la vez. Que reutilice `RegisterScanHandler` no exime de probarlo aqui — exime
 * de que se rompa por un camino distinto, que es otra cosa.
 *
 * **La trampa de la tarea 1.7 tiene prueba propia en este fichero.** Aquel
 * defecto —`worked_minutes` recalculado desde el estado ACTUAL de
 * `shift_entries` en el reenvio, en vez de devolverse el que se calculo la
 * primera vez— solo se destapo con un `clock_in`, un `clock_out` y el reenvio
 * del primero. Las pruebas de idempotencia de la 1.4 reenviaban un unico escaneo
 * y nunca lo ejercitaron. Aqui se reproduce esa secuencia exacta con el PIN.
 *
 * **Procesos de verdad y no un bucle** (ver `ParallelRequests`): diez llamadas
 * seguidas en el mismo proceso pasarian igual con una deduplicacion por `SELECT`
 * previo, que es justo la implementacion que la regla dura 8 prohibe. Por eso
 * este fichero usa `CommittedDatabase` y no `RefreshDatabase`.
 */

uses(CommittedDatabase::class);

const PIN_IDEMPOTENTE = '904471';

const PETICIONES_PARALELAS_PIN = 10;

/**
 * Escenario listo para fichar por PIN, con el reloj detenido.
 *
 * @return array{site: int, employee: string, code: string, publicKey: string, device: int, deviceUuid: string, token: string}
 */
function escenarioIdempotente(string $ahora = '2026-03-14 07:02:31'): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_IDEMPOTENTE);

    app()->instance(Clock::class, FixedClock::at($ahora));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    return [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
    ];
}

/**
 * @param  array{token: string, code: string, publicKey: string, ...}  $escenario
 * @return array<string, string>
 */
function cuerpoDePin(array $escenario, string $scanId, string $occurredAt): array
{
    return [
        'scan_id' => $scanId,
        'occurred_at' => $occurredAt,
        'employee_code' => $escenario['code'],
        'pin_sealed' => EmployeePins::seal(PIN_IDEMPOTENTE, $escenario['publicKey']),
    ];
}

it('crea un solo tramo y devuelve diez respuestas identicas con el mismo scan_id', function (): void {
    $escenario = escenarioIdempotente();

    // El MISMO `scan_id` en las diez: es el reenvio de la cola offline, no diez
    // gestos distintos.
    $scanId = Str::uuid7()->toString();

    $respuestas = ParallelRequests::run(
        PETICIONES_PARALELAS_PIN,
        static fn (): mixed => Api::as($escenario['token'])
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan/pin', cuerpoDePin($escenario, $scanId, '2026-03-14T07:02:31Z')),
    );

    $cuerpos = array_map(static fn (array $r): string => json_encode($r['body'], JSON_THROW_ON_ERROR), $respuestas);
    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    expect($respuestas)->toHaveCount(PETICIONES_PARALELAS_PIN)
        ->and(array_unique($codigos))->toBe([200])
        // Si alguna difiriera en un solo byte —otra `recorded_at`, otro
        // `action`— habria mas de un elemento.
        ->and(array_values(array_unique($cuerpos)))->toHaveCount(1);

    /** @var array<string, mixed> $primera */
    $primera = $respuestas[0]['body'];

    expect($primera['action'])->toBe('clock_in')
        ->and($primera['scan_id'])->toBe($scanId);

    // Una fila, un tramo, una proyeccion: lo decide el UNIQUE de
    // `scan_events.scan_id`, no un `SELECT` previo.
    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('clock_in')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('origin'))->toBe('pin_kiosk')
        // Y la marca de revision sobrevive al reenvio: si se perdiera, la
        // bandeja de la 2.5 dejaria de ver los fichajes que llegaron por la cola
        // offline, que son precisamente los mas dificiles de explicar.
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('flagged_for_review'))->toBeTrue()
        ->and(DB::table('daily_totals')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-07', 'RF-AT-11', 'RQ-03');

it('devuelve en el reenvio el acumulado que tenia el fichaje, no el de ahora', function (): void {
    // LA TRAMPA DE LA TAREA 1.7, con el PIN. `worked_minutes` se fija cuando el
    // fichaje ocurre y se guarda con el; recalcularlo en el reenvio da otra
    // cifra en cuanto un escaneo posterior del mismo dia cambia la jornada, que
    // es exactamente lo que ya ha pasado cuando la cola offline reintenta.
    // Devolver un numero distinto al original viola RF-AT-07 literalmente.
    $escenario = escenarioIdempotente('2026-03-14 07:00:00');

    $entrada = Str::uuid7()->toString();
    $cuerpoEntrada = cuerpoDePin($escenario, $entrada, '2026-03-14T07:00:00Z');

    $primera = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $entrada])
        ->post('/api/v1/scan/pin', $cuerpoEntrada);

    $primera->assertOk();

    expect($primera->json('worked_minutes'))->toBe(0);

    // Cuatro horas despues, la salida: la jornada pasa a tener 240 minutos.
    app()->instance(Clock::class, FixedClock::at('2026-03-14 11:00:00'));

    $salida = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $salida])
        ->post('/api/v1/scan/pin', cuerpoDePin($escenario, $salida, '2026-03-14T11:00:00Z'))
        ->assertOk()
        ->assertJsonPath('worked_minutes', 240);

    // Y ahora el reenvio de la ENTRADA, que es lo que hace la cola offline al
    // recuperar la red. Tiene que devolver 0, no 240.
    $reenvio = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $entrada])
        ->post('/api/v1/scan/pin', $cuerpoEntrada);

    $reenvio->assertOk();

    expect($reenvio->json('worked_minutes'))->toBe(0)
        ->and($reenvio->json('action'))->toBe('clock_in')
        // Y la recepcion original, no la de este reenvio: es lo que hace que la
        // respuesta sea IDENTICA y no solo parecida.
        ->and($reenvio->json('recorded_at'))->toBe($primera->json('recorded_at'))
        ->and($reenvio->json())->toBe($primera->json());

    // Y sigue habiendo un solo tramo abierto y cerrado, no tres.
    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(2);
})->group('RF-AT-07', 'RF-AT-11');

it('no gasta un intento del bloqueo al reenviar un fichaje ya registrado', function (): void {
    // El reenvio no vuelve a comprobar nada contra el contador de RS-12 de forma
    // que castigue a nadie: el PIN es el bueno, asi que verifica, y la
    // transaccion revierte por el UNIQUE. Lo que no puede pasar es que reenviar
    // un fichaje correcto acerque a su dueno al bloqueo.
    $escenario = escenarioIdempotente();

    $scanId = Str::uuid7()->toString();
    $cuerpo = cuerpoDePin($escenario, $scanId, '2026-03-14T07:02:31Z');

    for ($i = 0; $i < 5; $i++) {
        Api::as($escenario['token'])
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan/pin', $cuerpo)
            ->assertOk();
    }

    expect(DB::table('scan_events')->count())->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-07', 'RS-12');
