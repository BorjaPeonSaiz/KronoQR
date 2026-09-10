<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Observability\UnreachableCollector;
use Tests\Support\Time\FixedClock;

/*
 * **La idempotencia de RQ-03, con el SDK de trazas encendido y el colector
 * caido** (regla dura 8, RF-AT-07, decision 5 de la ficha 3.1).
 *
 * ## Por que este caso existe aparte
 *
 * `ScanIdempotencyConcurrencyTest` ya demuestra que diez peticiones paralelas
 * con el mismo `scan_id` producen un tramo y diez respuestas identicas. Lo
 * demuestra **sin SDK de trazas**, que es la instalacion de la mayoria y el
 * estado de serie.
 *
 * La tarea 3.1 mete en ese camino un exportador HTTP. A partir de ahi, la
 * garantia de la regla dura 8 deja de depender solo del UNIQUE de
 * `scan_events.scan_id` y pasa a depender tambien de que el SDK no cambie el
 * comportamiento del caso de uso bajo carrera: un span abierto y no cerrado
 * dentro de la rama de reintento, o un exportador que bloquea el proceso
 * mientras otro gana la carrera, se ven aqui y en ningun otro sitio.
 *
 * **No se duplica la suite entera**: un solo caso, el minimo, con el mismo
 * escenario y las mismas afirmaciones que el original. Si algun dia hay que
 * elegir, este es el que se queda.
 *
 * ## Procesos de verdad
 *
 * `CommittedDatabase` y {@see ParallelRequests} por lo mismo que en el fichero
 * original: diez llamadas seguidas en el mismo proceso pasarian igual con la
 * implementacion prohibida —un `SELECT` previo— y no probarian nada.
 *
 * ## El vaciado ocurre DENTRO del hijo
 *
 * `BatchSpanProcessor` acumula y envia al cerrar el proceso, y los hijos de
 * {@see ParallelRequests} terminan con `SIGKILL` a proposito: sin forzar el
 * envio, ninguno llegaria a tocar la red y la prueba no ejercitaria el
 * exportador. Se fuerza justo despues de la peticion, que es donde ocurriria en
 * PHP-FPM.
 */

uses(CommittedDatabase::class);

const PETICIONES_CON_TRAZAS = 10;

const TARJETA_CON_TRAZAS = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

it('sigue creando un solo tramo con el exportador de trazas apuntando a la nada', function (): void {
    $escenario = AttendanceFixtures::scenario();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_CON_TRAZAS, $escenario['employee']),
    );

    // El MISMO `scan_id` en las diez: es el reenvio de la cola offline, no diez
    // gestos distintos.
    $scanId = Str::uuid7()->toString();

    UnreachableCollector::around(function (TracerProvider $provider) use ($escenario, $scanId): void {
        $respuestas = ParallelRequests::run(
            PETICIONES_CON_TRAZAS,
            static function () use ($escenario, $scanId, $provider): mixed {
                $respuesta = Api::as($escenario['token'])
                    ->withHeaders([
                        'Idempotency-Key' => $scanId,
                        // La raiz de la traza es el `fetch` de la tablet
                        // (decision 7): el quiosco la envia en cada intento.
                        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                    ])
                    ->post('/api/v1/scan', [
                        'scan_id' => $scanId,
                        'occurred_at' => '2026-03-14T07:02:31Z',
                        'qr_payload' => TARJETA_CON_TRAZAS,
                    ]);

                // El envio de verdad contra el colector inexistente, dentro del
                // hijo y con la carrera todavia en marcha.
                $provider->forceFlush();

                return $respuesta;
            },
        );

        $cuerpos = array_map(static fn (array $r): string => json_encode($r['body'], JSON_THROW_ON_ERROR), $respuestas);
        $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

        expect($respuestas)->toHaveCount(PETICIONES_CON_TRAZAS)
            ->and(array_unique($codigos))->toBe([200])
            ->and(array_values(array_unique($cuerpos)))->toHaveCount(1);

        expect(DB::table('shift_entries')->count())->toBe(1)
            ->and(DB::table('scan_events')->count())->toBe(1)
            ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('clock_in')
            ->and(DB::table('daily_totals')->count())->toBe(1)
            ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
    });
})->group('RQ-03', 'RF-AT-07', 'RF-AT-10');
