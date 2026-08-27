<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;

/*
 * **Idempotencia bajo concurrencia** — escenario ineludible del doc 02 §9.4 y
 * RQ-03: *«10 peticiones paralelas con el mismo `scan_id` -> exactamente un
 * tramo, diez respuestas identicas»*.
 *
 * Es la prueba que decide si la regla dura 8 se cumple o se finge. El
 * `scan_id` lo genera el cliente y la cola offline del quiosco reintenta ante
 * fallo de red (RF-KI-04), asi que la misma peticion **llega varias veces y a la
 * vez**: si la deduplicacion fuera un `SELECT` previo, entre la consulta y la
 * insercion cabria otra peticion y el registro horario de alguien tendria dos
 * entradas para el mismo gesto. Aqui decide el UNIQUE de `scan_events.scan_id`.
 *
 * **Procesos de verdad y no un bucle.** Diez llamadas seguidas en el mismo
 * proceso pasarian igual con la implementacion prohibida; ver
 * {@see ParallelRequests}. Por eso este fichero usa {@see CommittedDatabase} en
 * lugar de `RefreshDatabase`: los hijos abren su propia conexion y no pueden ver
 * una transaccion sin confirmar.
 */

uses(CommittedDatabase::class);

const PETICIONES_PARALELAS = 10;

const TARJETA_CONCURRENTE = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

it('crea exactamente un tramo y devuelve diez respuestas identicas', function (): void {
    $escenario = AttendanceFixtures::scenario();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_CONCURRENTE, $escenario['employee']),
    );

    // El MISMO `scan_id` en las diez: es el reenvio de la cola offline, no diez
    // gestos distintos.
    $scanId = Str::uuid7()->toString();

    $respuestas = ParallelRequests::run(
        PETICIONES_PARALELAS,
        static fn (): mixed => Api::as($escenario['token'])
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan', [
                'scan_id' => $scanId,
                'occurred_at' => '2026-03-14T07:02:31Z',
                'qr_payload' => TARJETA_CONCURRENTE,
            ]),
    );

    // --- Diez respuestas identicas -------------------------------------------

    $cuerpos = array_map(static fn (array $r): string => json_encode($r['body'], JSON_THROW_ON_ERROR), $respuestas);
    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    expect($respuestas)->toHaveCount(PETICIONES_PARALELAS)
        ->and(array_unique($codigos))->toBe([200])
        // `array_unique` sobre los diez cuerpos serializados: si alguno
        // difiriera en un solo byte —otra `recorded_at`, otro `action`— habria
        // mas de un elemento.
        ->and(array_values(array_unique($cuerpos)))->toHaveCount(1);

    /** @var array<string, mixed> $primera */
    $primera = $respuestas[0]['body'];

    // Y la respuesta es la del fichaje que SI ocurrio, no un error ni un
    // anti-rebote: un reenvio de un `clock_in` devuelve `clock_in` (ADR-031).
    expect($primera['action'])->toBe('clock_in')
        ->and($primera['scan_id'])->toBe($scanId)
        ->and($primera['work_date'])->toBe('2026-03-14');

    // --- Exactamente un tramo ------------------------------------------------

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('clock_in')
        // Y una sola fila de proyeccion, con el `ON CONFLICT DO UPDATE`
        // haciendo su trabajo (RN-06, regla dura 7).
        ->and(DB::table('daily_totals')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-07', 'RQ-03');

it('crea un solo tramo aunque las diez peticiones traigan scan_id distintos', function (): void {
    // La otra cara de la misma carrera, y la que de verdad ocurre en un quiosco:
    // diez lecturas del mismo QR en el mismo segundo, cada una con su
    // identificador. Aqui la idempotencia por `scan_id` **no** aplica —son diez
    // escaneos distintos— y quien tiene que resolverlo es RF-AT-06.
    //
    // Es tambien la prueba de que el reintento del caso de uso funciona: el
    // primero abre turno, los otros nueve chocan contra
    // `one_open_shift_per_employee`, reintentan, y en el segundo intento ya ven
    // el tramo del ganador y se resuelven como anti-rebote.
    $escenario = AttendanceFixtures::scenario();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_CONCURRENTE, $escenario['employee']),
    );

    $respuestas = ParallelRequests::run(
        PETICIONES_PARALELAS,
        static function () use ($escenario): mixed {
            $scanId = Str::uuid7()->toString();

            return Api::as($escenario['token'])
                ->withHeaders(['Idempotency-Key' => $scanId])
                ->post('/api/v1/scan', [
                    'scan_id' => $scanId,
                    'occurred_at' => '2026-03-14T07:02:31Z',
                    'qr_payload' => TARJETA_CONCURRENTE,
                ]);
        },
    );

    $acciones = array_map(
        static fn (array $r): mixed => is_array($r['body']) ? ($r['body']['action'] ?? null) : null,
        $respuestas,
    );

    // Ninguna peticion falla —el empleado no tiene la culpa de haber pasado la
    // tarjeta a la vez que otro (regla dura 19)— y solo una crea tramo.
    expect(array_unique(array_map(static fn (array $r): int => $r['status'], $respuestas)))->toBe([200])
        ->and(array_count_values(array_filter($acciones, is_string(...)))['clock_in'] ?? 0)->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(1)
        // Los diez escaneos quedan registrados: `scan_events` es el log de TODO
        // escaneo, se acepte o no (doc 01 §5.5).
        ->and(DB::table('scan_events')->count())->toBe(PETICIONES_PARALELAS)
        ->and(DB::table('scan_events')->where('result', 'clock_in')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('result', 'rejected_debounce')->count())
        ->toBe(PETICIONES_PARALELAS - 1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-06', 'RN-01', 'RQ-03');
