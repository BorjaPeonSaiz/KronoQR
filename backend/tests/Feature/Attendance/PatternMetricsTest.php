<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Infrastructure\Metrics\RedisAnomalyMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Lo que la deteccion de patrones publica (doc 02 §8.2, RF-PR-06, tarea 3.11).
 *
 * DOS SOPORTES Y DOS PREGUNTAS DISTINTAS, y por eso las dos se comprueban aqui:
 *
 *   - `anomalous_patterns_detected_total{pattern}` sale por `GET /metrics`
 *     -Redis- con las DOS etiquetas nuevas, `kiosk_coincidence` e
 *     `impossible_sequence`. Es la serie que grafica el cuadro «Negocio» y la
 *     metrica de negocio del doc 01 §9.2: «Patrones anomalos detectados por
 *     tipo. **Se revisa, no se sanciona automaticamente**».
 *   - `pattern_detection_last_run_timestamp_seconds` y
 *     `pattern_detection_last_failures` salen por el colector *textfile*, que es
 *     lo que sobrevive a un `FLUSHALL` de despliegue. Son las que sostienen
 *     `DeteccionDePatronesAusente` y `DeteccionDePatronesConFallos`.
 *
 * EL HALLAZGO NO ALERTA (decision 9 de la ficha). Lo que alerta es que la pasada
 * no corra o falle, que es operacion; un indicio sobre dos personas concretas se
 * revisa en la bandeja, no en un canal de guardia.
 *
 * SIN ETIQUETA POR PERSONA en ninguna de las tres (regla dura 21): una serie
 * temporal por empleado seria un registro de quien es sospechoso, con retencion
 * indefinida y sin control de acceso.
 */

uses(RefreshDatabase::class);

/** El «ahora» de la pasada: las 04:35 UTC, su hora en el planificador. */
const PATTERN_METRICS_NOW = '2026-03-20 04:35:00';

/** La red desde la que Prometheus sondea, como en `MetricsEndpointTest`. */
const PATTERN_PROBE_IP = '10.91.0.5';

beforeEach(function (): void {
    config(['observability.metrics.allow_cidr' => '10.91.0.0/24']);

    Config::set('observability.metrics.enabled', true);
    Config::set(
        'observability.metrics.textfile_path',
        storage_path('framework/testing/textfile-patterns-'.Str::random(10)),
    );

    // Contador acumulado en Redis: la base de datos si se rehace entre pruebas,
    // esto no.
    app(Redis::class)->connection()->command('DEL', [RedisAnomalyMetrics::ANOMALIES_TOTAL]);
});

afterEach(function (): void {
    app(Redis::class)->connection()->command('DEL', [RedisAnomalyMetrics::ANOMALIES_TOTAL]);

    $directory = rtrim(Config::string('observability.metrics.textfile_path'), '/');

    foreach (glob($directory.'/*') ?: [] as $leftover) {
        if (is_file($leftover)) {
            unlink($leftover);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
});

/**
 * Centro en Madrid con dos personas y dos quioscos.
 *
 * @return array{site: int, ana: string, bruno: string, device: int, otherDevice: int}
 */
function escenarioDePatrones(): array
{
    $site = WorkforceFixtures::site('Hotel de metricas de patrones', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site);

    return [
        'site' => $site,
        'ana' => WorkforceFixtures::employee($site, $department),
        'bruno' => WorkforceFixtures::employee($site, $department),
        'device' => AttendanceFixtures::device($site, 'Recepcion')['id'],
        'otherDevice' => AttendanceFixtures::device($site, 'Cocina')['id'],
    ];
}

function escaneoDePatron(string $employeeUuid, int $deviceId, string $occurredAt): void
{
    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $deviceId,
        'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
        'occurred_at' => $occurredAt,
        'recorded_at' => $occurredAt,
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'shift_entry_id' => null,
        'worked_minutes' => 0,
        'client_meta' => '{}',
        'flagged_for_review' => false,
    ]);
}

/** La tarjeta de Carla, la unica que el doble del resolutor conoce. */
const TARJETA_DE_CARLA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * Un fichaje **de verdad**: por el endpoint del quiosco y pasando por
 * `RegisterScanHandler` (decision 15). Devuelve el `action` de la respuesta.
 */
function fichajeDePatron(int $deviceId, string $occurredAt): string
{
    FrozenTime::at($occurredAt);

    $scanId = Str::uuid7()->toString();

    $response = Api::as(AttendanceFixtures::tokenFor($deviceId))
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => str_replace(' ', 'T', $occurredAt).'Z',
            'qr_payload' => TARJETA_DE_CARLA,
        ]);

    $response->assertOk();

    $action = $response->json('action');

    return is_string($action) ? $action : '';
}

/** El fichero *textfile* de la deteccion de patrones. */
function patternTextfile(): string
{
    return rtrim(Config::string('observability.metrics.textfile_path'), '/').'/kronoqr_pattern_detection.prom';
}

function correPatrones(): int
{
    FrozenTime::at(PATTERN_METRICS_NOW);

    return Artisan::call('attendance:detect-patterns');
}

it('publica las dos etiquetas de patron en /metrics, y no las mezcla en un solo cubo', function (): void {
    // Las dos formas del hallazgo van a etiquetas distintas porque se revisan
    // distinto: una coincidencia sistematica se contrasta con el cuadrante y una
    // imposibilidad fisica con los dos quioscos. Contarlas juntas dejaria la
    // serie sin poder responder ninguna de las dos preguntas.
    $escenario = escenarioDePatrones();

    for ($day = 10; $day <= 14; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        escaneoDePatron($escenario['ana'], $escenario['device'], $date.' 06:00:00+00');
        escaneoDePatron($escenario['bruno'], $escenario['device'], $date.' 06:00:04+00');
    }

    // Y una secuencia imposible de una tercera persona, para que las dos
    // etiquetas coexistan en la misma pasada. **Los dos escaneos se registran
    // por el camino de fichaje de verdad** (decision 15): escritos a mano
    // producian un par que el sistema nunca escribe, porque el anti-rebote de
    // RF-AT-06 es por persona y la segunda presentacion 30 s despues en otra
    // tablet sale `rejected_debounce`.
    $carla = WorkforceFixtures::employee($escenario['site']);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DE_CARLA, $carla),
    );

    expect(fichajeDePatron($escenario['device'], '2026-03-14 10:00:00'))->toBe('clock_in')
        ->and(fichajeDePatron($escenario['otherDevice'], '2026-03-14 10:00:30'))->toBe('debounced');

    expect(correPatrones())->toBe(0);

    $body = (string) Api::guest()->fromIp(PATTERN_PROBE_IP)->get('/metrics')->getContent();

    expect($body)
        ->toContain('anomalous_patterns_detected_total{pattern="kiosk_coincidence"} 2')
        ->toContain('anomalous_patterns_detected_total{pattern="impossible_sequence"} 1');

    // Regla dura 21: ni una etiqueta que identifique a nadie. Con
    // `str_contains` y no con `->not->toContain()` porque sobre una expectativa
    // de `string|null` el analisis estatico no resuelve `->not` (PHPStan 9), y
    // una asercion que no compila no protege nada.
    expect(str_contains($body, $escenario['ana']))->toBeFalse()
        ->and(str_contains($body, $carla))->toBeFalse();
})->group('RF-PR-06', 'RN-16');

it('publica la frescura y los fallos de la pasada por el colector textfile', function (): void {
    $escenario = escenarioDePatrones();
    escaneoDePatron($escenario['ana'], $escenario['device'], '2026-03-14 06:00:00+00');

    correPatrones();

    $publicado = (string) file_get_contents(patternTextfile());

    expect($publicado)
        ->toContain('# TYPE pattern_detection_last_run_timestamp_seconds gauge')
        ->toContain('pattern_detection_last_run_timestamp_seconds '.strtotime(PATTERN_METRICS_NOW.'+00:00'))
        ->toContain('# TYPE pattern_detection_last_failures gauge')
        ->toContain('pattern_detection_last_failures 0');
})->group('RF-PR-06');

it('publica la frescura tambien cuando la pasada no encuentra nada', function (): void {
    // Una noche tranquila es lo normal. Si la serie solo apareciera cuando hay
    // hallazgos, `DeteccionDePatronesAusente` no podria distinguir eso de un
    // planificador parado, que es justo lo que existe para ver.
    escenarioDePatrones();

    expect(correPatrones())->toBe(0);

    expect((string) file_get_contents(patternTextfile()))
        ->toContain('pattern_detection_last_run_timestamp_seconds '.strtotime(PATTERN_METRICS_NOW.'+00:00'))
        ->toContain('pattern_detection_last_failures 0');
})->group('RF-PR-06');

it('publica la frescura tambien sin centro de trabajo', function (): void {
    // RF-PD-03: antes de la puesta en marcha no hay centro y la pasada no revisa
    // nada. No es un error -el comando sale con cero- y la serie se publica
    // igual, con ceros.
    expect(correPatrones())->toBe(0);

    expect((string) file_get_contents(patternTextfile()))
        ->toContain('pattern_detection_last_run_timestamp_seconds '.strtotime(PATTERN_METRICS_NOW.'+00:00'))
        ->toContain('pattern_detection_last_failures 0');
})->group('RF-PR-06', 'RF-PD-03');
