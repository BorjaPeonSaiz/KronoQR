<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
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

/*
 * `POST /api/v1/scan/batch` de punta a punta, validado contra
 * `docs/api/openapi.yaml` en cada respuesta (doc 02 §9.5, nivel «feature +
 * contrato»).
 *
 * Cubre el escenario ineludible del §9.4 —*«lote desordenado: entrada y salida
 * encoladas, enviadas en orden inverso, procesadas correctamente por
 * `occurred_at`»*— del lado del endpoint, que es donde se ve el efecto sobre el
 * registro horario. El orden en si lo prueba `Tests\Unit\Attendance\Application\
 * ScanBatchTest`, sin base de datos.
 *
 * **El reloj esta detenido** (regla dura 2, ADR-021): sin eso, `recorded_at` y el
 * retraso de sincronizacion cambiarian en cada ejecucion.
 *
 * **La credencial la resuelve un doble**, por lo mismo que en `RegisterScanTest`:
 * lo que se comprueba aqui es que un lote se convierte en tramos, no que el HMAC
 * verifique (ADR-025).
 */

uses(RefreshDatabase::class);

const TARJETA_LOTE = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const TARJETA_REVOCADA_LOTE = 'FH1.a3.0000000000000000000000.0000000000000000';

const SINCRONIZADO_A_LAS = '2026-03-14 18:00:00';

/**
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, metrics: RecordingScanMetrics}
 */
function escenarioDeLote(): array
{
    $escenario = AttendanceFixtures::scenario();

    app()->instance(Clock::class, FixedClock::at(SINCRONIZADO_A_LAS));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(TARJETA_LOTE, $escenario['employee'])
            ->rejecting(TARJETA_REVOCADA_LOTE, CredentialRejectionReason::REVOKED),
    );

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    return [...$escenario, 'metrics' => $metricas];
}

/**
 * @param  array{token: string, ...}  $escenario
 * @param  list<array<string, mixed>>  $scans
 * @return TestResponse<Response>
 */
function sincronizar(array $escenario, array $scans, ?string $batchKey = null): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $batchKey ?? Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', ['scans' => $scans]);
}

/**
 * @return array{scan_id: string, occurred_at: string, qr_payload: string}
 */
function escaneoEncolado(string $occurredAt, string $payload = TARJETA_LOTE, ?string $scanId = null): array
{
    return [
        'scan_id' => $scanId ?? Str::uuid7()->toString(),
        'occurred_at' => $occurredAt,
        'qr_payload' => $payload,
    ];
}

// --- El camino normal --------------------------------------------------------

it('procesa el lote en orden de occurred_at aunque llegue del reves', function (): void {
    // Escenario ineludible del §9.4. La salida se envia PRIMERO —su reintento gano
    // la carrera— y la entrada detras. Si se procesara por orden de llegada, el
    // cierre sin turno abierto se convertiria en una entrada y el resultado seria
    // una jornada inventada con dos horas reales.
    $escenario = escenarioDeLote();

    $salida = escaneoEncolado('2026-03-14T15:03:12Z');
    $entrada = escaneoEncolado('2026-03-14T07:02:31Z');

    $respuesta = sincronizar($escenario, [$salida, $entrada]);

    $respuesta->assertStatus(207)->assertValidRequest()->assertValidResponse();

    // Los resultados vienen en el orden en que se procesaron, no en el que se
    // enviaron: el contrato lo promete y aqui se comprueba.
    expect($respuesta->json('results.0.scan_id'))->toBe($entrada['scan_id'])
        ->and($respuesta->json('results.0.outcome.action'))->toBe('clock_in')
        ->and($respuesta->json('results.1.scan_id'))->toBe($salida['scan_id'])
        ->and($respuesta->json('results.1.outcome.action'))->toBe('clock_out')
        // 8 h 0 min 41 s: el tramo se cierra con la duracion real, no con la hora
        // de llegada del lote (regla dura 9).
        ->and($respuesta->json('results.1.outcome.worked_minutes'))->toBe(480);

    $tramo = DB::table('shift_entries')->first();

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and($tramo?->status)->toBe('closed')
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-KI-04', 'RF-AT-09', 'RQ-06');

it('registra el occurred_at original y no la hora de llegada', function (): void {
    // Regla dura 9 y RF-AT-09: el registro legal usa `occurred_at`. El lote llega
    // a las 18:00 y el fichaje es de las 07:02.
    $escenario = escenarioDeLote();

    $respuesta = sincronizar($escenario, [escaneoEncolado('2026-03-14T07:02:31Z')]);

    $respuesta->assertStatus(207)->assertValidResponse();

    $tramo = DB::table('shift_entries')->first();
    $evento = DB::table('scan_events')->first();

    expect($respuesta->json('results.0.outcome.occurred_at'))->toStartWith('2026-03-14T07:02:31')
        ->and($respuesta->json('results.0.outcome.recorded_at'))->toStartWith('2026-03-14T18:00:00')
        ->and((string) $tramo?->clocked_in_at)->toContain('07:02:31')
        // Y el desfase entre las dos marcas queda escrito para la incidencia de la
        // tarea 3.5 (RF-AT-10): 39.449 s = 10 h 57 min 29 s.
        ->and((int) ($evento->clock_skew_seconds ?? 0))->toBe(39_449);
})->group('RF-AT-09', 'RF-AT-10');

it('devuelve un 207 aunque todos los elementos se acepten', function (): void {
    // El codigo describe la FORMA de la respuesta —un resultado por elemento—, no
    // el desenlace agregado. Un lote todo correcto y otro todo rechazado tienen el
    // mismo codigo, que es lo que impide que el cliente ramifique por el codigo de
    // la peticion en vez de por el de cada elemento.
    $escenario = escenarioDeLote();

    sincronizar($escenario, [escaneoEncolado('2026-03-14T07:02:31Z')])
        ->assertStatus(207)
        ->assertValidResponse();
})->group('RF-KI-04', 'RQ-06');

it('no deja que un elemento rechazado invalide el lote', function (): void {
    // Regla dura 19. Una tarjeta revocada entre dos fichajes validos: los dos
    // validos se registran y el rechazo es de su elemento, no del envio.
    $escenario = escenarioDeLote();

    $respuesta = sincronizar($escenario, [
        escaneoEncolado('2026-03-14T07:02:31Z'),
        escaneoEncolado('2026-03-14T09:00:00Z', TARJETA_REVOCADA_LOTE),
        escaneoEncolado('2026-03-14T15:03:12Z'),
    ]);

    $respuesta->assertStatus(207)->assertValidResponse();

    expect($respuesta->json('results.0.status'))->toBe(200)
        ->and($respuesta->json('results.1.status'))->toBe(422)
        ->and($respuesta->json('results.2.status'))->toBe(200)
        // El tramo del primero se cerro con el tercero: el rechazo del segundo no
        // interrumpio la jornada.
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->first()?->status)->toBe('closed')
        // Y los tres escaneos quedan en el log, se aceptaran o no (doc 01 §5.5).
        ->and(DB::table('scan_events')->count())->toBe(3)
        ->and(DB::table('scan_events')->where('result', 'rejected_revoked')->count())->toBe(1);
})->group('RF-KI-04', 'RS-03');

it('no revela la causa del rechazo de un elemento', function (): void {
    // Regla dura 17 llevada al lote: el cuerpo del elemento rechazado es el mismo
    // `ScanRejected` generico, sin hueco donde alojar la causa. Se comprueba con
    // una tarjeta revocada y con una desconocida: los dos cuerpos, identicos.
    $escenario = escenarioDeLote();

    $revocada = escaneoEncolado('2026-03-14T07:02:31Z', TARJETA_REVOCADA_LOTE);
    $inventada = escaneoEncolado('2026-03-14T07:05:31Z', 'FH1.zz.1111111111111111111111.1111111111111111');

    $respuesta = sincronizar($escenario, [$revocada, $inventada]);

    $respuesta->assertStatus(207)->assertValidResponse();

    /** @var array<string, mixed> $primero */
    $primero = $respuesta->json('results.0.outcome');
    /** @var array<string, mixed> $segundo */
    $segundo = $respuesta->json('results.1.outcome');

    // Lo unico que difiere es el eco del `scan_id`.
    unset($primero['scan_id'], $segundo['scan_id']);

    expect($primero)->toBe($segundo)
        ->and($primero['type'])->toBe('urn:kronoqr:problem:scan-rejected')
        // Y la causa real si queda del lado del servidor, que es lo que pide el
        // escenario «QR falsificado» del doc 01 §11.
        ->and(DB::table('scan_events')->where('result', 'rejected_revoked')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('result', 'rejected_signature')->count())->toBe(0)
        ->and(DB::table('scan_events')->where('result', 'rejected_unknown')->count())->toBe(1);
})->group('RS-03', 'RF-QR-02');

it('devuelve el anti-rebote como desenlace aceptado dentro del lote', function (): void {
    // ADR-031: el anti-rebote viaja con `200` tambien aqui. Si fuera `4xx`, la cola
    // offline lo conservaria y reintentaria contra una ventana que ya paso — una
    // cola que no drena es la regla dura 19 incumplida con retraso.
    $escenario = escenarioDeLote();

    $respuesta = sincronizar($escenario, [
        escaneoEncolado('2026-03-14T07:02:31Z'),
        // 20 s despues: dentro de la ventana de gracia de 60 s (RF-AT-06).
        escaneoEncolado('2026-03-14T07:02:51Z'),
    ]);

    $respuesta->assertStatus(207)->assertValidResponse();

    expect($respuesta->json('results.0.outcome.action'))->toBe('clock_in')
        ->and($respuesta->json('results.1.status'))->toBe(200)
        ->and($respuesta->json('results.1.outcome.action'))->toBe('debounced')
        ->and($respuesta->json('results.1.outcome.last_accepted_at'))->toStartWith('2026-03-14T07:02:31')
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-06', 'RF-KI-04');

// --- Idempotencia ------------------------------------------------------------

it('devuelve la respuesta original al reenviar el mismo lote', function (): void {
    // Regla dura 8 y RF-AT-07: la cola reintenta ante fallo de red, asi que el
    // mismo lote llega dos veces. La deduplicacion es elemento a elemento, por el
    // UNIQUE de `scan_events.scan_id`.
    $escenario = escenarioDeLote();

    $scans = [
        escaneoEncolado('2026-03-14T07:02:31Z'),
        escaneoEncolado('2026-03-14T15:03:12Z'),
    ];

    $primera = sincronizar($escenario, $scans);
    $segunda = sincronizar($escenario, $scans);

    $primera->assertStatus(207)->assertValidResponse();
    $segunda->assertStatus(207)->assertValidResponse();

    expect($segunda->json('results'))->toBe($primera->json('results'))
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(2)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-07', 'RQ-03');

it('deduplica tambien un lote que solapa con otro ya enviado', function (): void {
    // El caso real: la respuesta del primer lote se perdio, el quiosco reenvia sus
    // elementos junto con los nuevos. Los repetidos devuelven lo de antes y los
    // nuevos se registran.
    $escenario = escenarioDeLote();

    $entrada = escaneoEncolado('2026-03-14T07:02:31Z');
    $salida = escaneoEncolado('2026-03-14T15:03:12Z');

    sincronizar($escenario, [$entrada])->assertStatus(207);

    $segunda = sincronizar($escenario, [$entrada, $salida]);

    $segunda->assertStatus(207)->assertValidResponse();

    expect($segunda->json('results.0.outcome.action'))->toBe('clock_in')
        ->and($segunda->json('results.1.outcome.action'))->toBe('clock_out')
        ->and(DB::table('scan_events')->count())->toBe(2)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-07', 'RF-KI-04');

it('devuelve el mismo resultado para un scan_id repetido dentro del mismo lote', function (): void {
    // Un cliente que encola dos veces el mismo escaneo. No se deduplica antes de
    // procesar —eso seria un `SELECT` previo con otro nombre— sino que el segundo
    // choca contra el UNIQUE y se responde con lo que quedo registrado.
    $escenario = escenarioDeLote();

    $escaneo = escaneoEncolado('2026-03-14T07:02:31Z');

    $respuesta = sincronizar($escenario, [$escaneo, $escaneo]);

    $respuesta->assertStatus(207)->assertValidResponse();

    expect($respuesta->json('results.0.outcome'))->toBe($respuesta->json('results.1.outcome'))
        ->and(DB::table('scan_events')->count())->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-AT-07', 'RQ-03');

// --- Validacion --------------------------------------------------------------

it('rechaza el lote entero si un elemento no cumple el contrato', function (): void {
    // Decision documentada en `RegisterScanBatchRequest`: los tres campos los
    // genera el propio quiosco, asi que un elemento mal formado describe un cliente
    // roto y no una tarjeta mala. `400` y no `422`, que en este camino significa
    // «tarjeta rechazada».
    $escenario = escenarioDeLote();

    $respuesta = sincronizar($escenario, [
        escaneoEncolado('2026-03-14T07:02:31Z'),
        // Con desfase explicito en lugar de `Z`: regla dura 3.
        escaneoEncolado('2026-03-14T09:00:00+02:00'),
    ]);

    $respuesta->assertStatus(400)->assertValidResponse();

    expect($respuesta->json('type'))->toBe('urn:kronoqr:problem:invalid-request')
        // Y no se registra NADA: el lote no se proceso a medias.
        ->and(DB::table('scan_events')->count())->toBe(0);
})->group('RN-04', 'RQ-06');

it('rechaza un lote de mas de cincuenta escaneos', function (): void {
    $escenario = escenarioDeLote();

    $scans = [];

    for ($i = 0; $i <= 50; $i++) {
        $scans[] = escaneoEncolado(sprintf('2026-03-14T%02d:%02d:00Z', 6 + intdiv($i, 60), $i % 60));
    }

    sincronizar($escenario, $scans)->assertStatus(400)->assertValidResponse();

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RF-KI-04');

it('rechaza un lote vacio', function (): void {
    $escenario = escenarioDeLote();

    sincronizar($escenario, [])->assertStatus(400)->assertValidResponse();
})->group('RF-KI-04');

it('rechaza un campo desconocido dentro de un elemento', function (): void {
    // `device_id` es el ejemplo que importa: quien lo envia se va convencido de
    // haber atribuido el fichaje a otro quiosco, y no ha atribuido nada — el
    // dispositivo sale del token.
    $escenario = escenarioDeLote();

    $escaneo = escaneoEncolado('2026-03-14T07:02:31Z');
    $escaneo['device_id'] = 99;

    $respuesta = sincronizar($escenario, [$escaneo]);

    $respuesta->assertStatus(400)->assertValidResponse();

    expect($respuesta->json('errors'))->toHaveKey('scans.0.device_id');
})->group('RQ-06', 'RS-04');

it('exige la cabecera Idempotency-Key del lote', function (): void {
    $escenario = escenarioDeLote();

    $respuesta = Api::as($escenario['token'])
        ->post('/api/v1/scan/batch', ['scans' => [escaneoEncolado('2026-03-14T07:02:31Z')]]);

    $respuesta->assertStatus(400);

    expect($respuesta->json('errors'))->toHaveKey('Idempotency-Key')
        ->and(DB::table('scan_events')->count())->toBe(0);
})->group('RF-AT-07');

// --- Instrumentacion ---------------------------------------------------------

it('mide el retraso de sincronizacion y cuenta cada escaneo del lote', function (): void {
    // §8.2: `sync_delay_seconds{device}` sale del elemento mas antiguo y
    // `scans_total{device,result}` cuenta igual que si el escaneo hubiera llegado
    // solo. Sin esto, la metrica del cambio de turno se desplomaria justo despues
    // de un corte de red, que es cuando mas se mira.
    $escenario = escenarioDeLote();

    sincronizar($escenario, [
        escaneoEncolado('2026-03-14T07:02:31Z'),
        escaneoEncolado('2026-03-14T15:03:12Z'),
    ])->assertStatus(207);

    $metricas = $escenario['metrics'];

    expect($metricas->observations)->toHaveCount(2)
        ->and($metricas->batches)->toHaveCount(1)
        ->and($metricas->batches[0]['device'])->toBe($escenario['deviceUuid'])
        ->and($metricas->batches[0]['size'])->toBe(2)
        // 18:00:00 - 07:02:31 = 10 h 57 min 29 s.
        ->and($metricas->batches[0]['delay'])->toBe(39_449);
})->group('RF-KI-04', 'RNF-P-02');
