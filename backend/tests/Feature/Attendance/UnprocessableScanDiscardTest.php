<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * **Un fichaje que el servidor no consigue procesar se descarta tras un umbral,
 * y la cola sigue** (RN-22, ADR-061, tarea 17.9 de la 2.3.0), por las TRES
 * rutas de escritura del fichaje y validado contra el contrato en cada
 * respuesta (doc 02 §9.5: feature + contrato).
 *
 * El veneno es un `SiteCalendar` que lanza siempre al registrar: un defecto que
 * salta despues de resolver la credencial, de modo que la atribucion del
 * descarte —que usa el mismo resolutor que el aviso del quiosco— si funciona.
 * Es justo el caso que motiva ADR-061: un fallo que ningun reintento cura.
 *
 * El reloj se mueve a mano: la politica mira el reloj del SERVIDOR (regla
 * dura 2), y el umbral de serie es 10 intentos durante 60 minutos.
 */

uses(RefreshDatabase::class);

const DESCARTE_TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const DESCARTE_PIN = '904471';

const DESCARTE_INICIO = '2026-03-14 08:00:00';

const DESCARTE_TIPO = 'urn:kronoqr:problem:scan-discarded';

/**
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, metrics: RecordingScanMetrics, calendar: SiteCalendar}
 */
function escenarioDeDescarte(): array
{
    $escenario = AttendanceFixtures::scenario();

    FrozenTime::at(DESCARTE_INICIO);

    // Una tarjeta AUTENTICA y con su emision: la atribucion del descarte es la
    // del aviso del quiosco (ADR-047 §2) y sin emision no atribuye.
    app()->instance(CredentialResolver::class, new class($escenario['employee']) implements CredentialResolver
    {
        public function __construct(private readonly string $employee) {}

        public function resolve(string $qrPayload): CredentialResolution
        {
            return CredentialResolution::resolved($this->employee, new DateTimeImmutable('2026-01-01T00:00:00Z'));
        }
    });

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    return [...$escenario, 'metrics' => $metricas, 'calendar' => app(SiteCalendar::class)];
}

/**
 * Un defecto que salta siempre al registrar: el veneno.
 */
function envenenarCalendario(): void
{
    app()->instance(SiteCalendar::class, new class implements SiteCalendar
    {
        public function timezoneOf(int $siteId): ?DateTimeZone
        {
            throw new LogicException('Defecto reproducible al calcular la zona del centro.');
        }

        public function timezoneForRecording(int $siteId): ?DateTimeZone
        {
            throw new LogicException('Defecto reproducible al calcular la zona del centro.');
        }
    });
}

/**
 * @param  array{calendar: SiteCalendar, ...}  $escenario
 */
function curarCalendario(array $escenario): void
{
    app()->instance(SiteCalendar::class, $escenario['calendar']);
}

/**
 * @param  array{token: string, ...}  $escenario
 * @param  list<array<string, mixed>>  $scans
 * @return TestResponse<Response>
 */
function loteDeDescarte(array $escenario, array $scans): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', ['scans' => $scans]);
}

/**
 * @param  array{token: string, ...}  $escenario
 * @param  array{scan_id: string, occurred_at: string, qr_payload: string}  $scan
 * @return TestResponse<Response>
 */
function escaneoSueltoDeDescarte(array $escenario, array $scan): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scan['scan_id']])
        ->post('/api/v1/scan', $scan);
}

/**
 * @return array{scan_id: string, occurred_at: string, qr_payload: string}
 */
function fichajeDeDescarte(string $occurredAt): array
{
    return ['scan_id' => Str::uuid7()->toString(), 'occurred_at' => $occurredAt, 'qr_payload' => DESCARTE_TARJETA];
}

/**
 * Siembra la historia de un fichaje ya fallado `$intentos` veces, el primero
 * hace `$minutos` minutos, con la misma huella que calcula el guardian.
 */
function sembrarFallos(int $deviceId, string $scanId, string $huella, int $intentos, int $minutos, string $origin = 'qr_kiosk'): void
{
    $primero = (new DateTimeImmutable(DESCARTE_INICIO.' UTC'))->modify('-'.$minutos.' minutes');

    for ($i = 0; $i < $intentos; $i++) {
        DB::table('scan_processing_failures')->insert([
            'scan_id' => $scanId,
            'device_id' => $deviceId,
            'origin' => $origin,
            'payload_fingerprint' => $huella,
            'failed_at' => $primero->modify('+'.$i.' minutes')->format('Y-m-d H:i:s.uP'),
            'exception_class' => LogicException::class,
        ]);
    }
}

/**
 * Siembra `$cuantos` descartes del servidor de este quiosco en las ultimas 24 h.
 */
function sembrarDescartesDelServidor(int $deviceId, int $cuantos): void
{
    for ($i = 0; $i < $cuantos; $i++) {
        DB::table('discarded_scan_reports')->insert([
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $deviceId,
            'origin' => 'qr_kiosk',
            'occurred_at' => '2026-03-14 05:00:00+00',
            'discarded_at' => '2026-03-14 06:00:00+00',
            'recorded_at' => '2026-03-14 06:00:00+00',
            'http_status' => 422,
            'problem_type' => DESCARTE_TIPO,
            'owner_employee_id' => null,
            'attribution' => 'none',
            'credential_issued_at' => null,
            'already_recorded' => false,
            'source' => 'server',
            'failed_attempts' => 10,
            'first_failed_at' => '2026-03-14 05:00:00+00',
        ]);
    }
}

// --- El lote: diez intentos durante una hora y la cola sigue -------------------

it('descarta en el lote el elemento que falla diez veces durante una hora y procesa el que va detras', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $entrada = fichajeDeDescarte('2026-03-14T07:00:00Z');
    $salida = fichajeDeDescarte('2026-03-14T15:00:00Z');

    // Nueve intentos, cada siete minutos: 56 minutos. Dentro de la ventana todo
    // sigue como antes de ADR-061: 503 y aplazado.
    for ($i = 0; $i < 9; $i++) {
        FrozenTime::at((new DateTimeImmutable(DESCARTE_INICIO.' UTC'))->modify('+'.(7 * $i).' minutes')->format('Y-m-d H:i:s'));

        $respuesta = loteDeDescarte($escenario, [$entrada, $salida]);

        $respuesta->assertStatus(207)->assertValidResponse();

        expect($respuesta->json('results.0.outcome.type'))->toBe('urn:kronoqr:problem:scan-not-processed')
            ->and($respuesta->json('results.1.outcome.type'))->toBe('urn:kronoqr:problem:scan-held-back');
    }

    expect(DB::table('scan_processing_failures')->where('scan_id', $entrada['scan_id'])->count())->toBe(9)
        ->and(DB::table('scan_events')->count())->toBe(0);

    // El decimo, a los 63 minutos: descartado. Y el veneno sigue: la salida que
    // va detras tambien falla, asi que se cura antes para ver que se procesa.
    FrozenTime::at('2026-03-14 09:03:00');

    $calendarioEnvenenado = app(SiteCalendar::class);
    $guardian = new class($calendarioEnvenenado, $escenario['calendar']) implements SiteCalendar
    {
        private int $llamadas = 0;

        public function __construct(private readonly SiteCalendar $veneno, private readonly SiteCalendar $sano) {}

        public function timezoneOf(int $siteId): ?DateTimeZone
        {
            return $this->sano->timezoneOf($siteId);
        }

        public function timezoneForRecording(int $siteId): ?DateTimeZone
        {
            // Solo el primer elemento del lote —la entrada— sigue envenenado.
            return $this->llamadas++ === 0 ? $this->veneno->timezoneForRecording($siteId) : $this->sano->timezoneForRecording($siteId);
        }
    };
    app()->instance(SiteCalendar::class, $guardian);

    $decimo = loteDeDescarte($escenario, [$entrada, $salida]);

    $decimo->assertStatus(207)->assertValidResponse();

    expect($decimo->json('results.0.scan_id'))->toBe($entrada['scan_id'])
        ->and($decimo->json('results.0.status'))->toBe(422)
        ->and($decimo->json('results.0.outcome'))->toBe([
            'type' => DESCARTE_TIPO,
            'title' => 'Escaneo descartado',
            'status' => 422,
            'detail' => 'El escaneo no se ha podido procesar y queda pendiente de revision.',
            'scan_id' => $entrada['scan_id'],
        ])
        // RN-21 hasta el umbral, no para siempre: la salida se procesa.
        ->and($decimo->json('results.1.status'))->toBe(200)
        ->and($decimo->json('results.1.outcome.action'))->toBe('clock_in');

    $fila = DB::table('scan_events')->where('scan_id', $entrada['scan_id'])->first();
    $aviso = DB::table('discarded_scan_reports')->where('scan_id', $entrada['scan_id'])->first();
    $asiento = DB::table('audit_log')->where('action', 'scan.discarded')->first();

    expect($fila?->result)->toBe('discarded_unprocessable')
        ->and($fila?->shift_entry_id)->toBeNull()
        ->and($fila?->worked_minutes)->toBeNull()
        ->and($fila?->employee_id)->toBe(AttendanceFixtures::employeeIdOf($escenario['employee']))
        // El legal usa `occurred_at` (regla dura 9); la recepcion es la del descarte.
        ->and((new DateTimeImmutable((string) $fila?->occurred_at))->format('c'))->toBe('2026-03-14T07:00:00+00:00')
        ->and((new DateTimeImmutable((string) $fila?->recorded_at))->format('c'))->toBe('2026-03-14T09:03:00+00:00')
        ->and($aviso?->source)->toBe('server')
        ->and($aviso?->http_status)->toBe(422)
        ->and($aviso?->problem_type)->toBe(DESCARTE_TIPO)
        ->and($aviso?->failed_attempts)->toBe(10)
        ->and($aviso?->attribution)->toBe('credential')
        ->and($aviso?->already_recorded)->toBeFalse()
        ->and($asiento?->actor_type)->toBe('system')
        ->and($asiento?->subject_type)->toBe('device')
        ->and($asiento?->subject_id)->toBe($escenario['device'])
        ->and($escenario['metrics']->serverDiscarded)->toBe([['device' => $escenario['deviceUuid'], 'attributed' => true]]);

    /** @var array<string, mixed> $carga */
    $carga = json_decode((string) $asiento?->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($carga)->toMatchArray([
        'device_uuid' => $escenario['deviceUuid'],
        'scan_id' => $entrada['scan_id'],
        'attempts' => 10,
        'attribution' => 'credential',
        'employee_uuid' => $escenario['employee'],
    ])
        // Sin clase de excepcion ni texto libre (reglas duras 6 y 21).
        ->and(json_encode($carga))->not->toContain('LogicException')
        ->and(json_encode($carga))->not->toContain(DESCARTE_TARJETA);

    // Nunca un tramo por el descarte: el unico es el de la salida procesada.
    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RN-22', 'RN-21', 'RF-KI-04', 'RF-AT-09');

it('sigue conservando con diez intentos si no ha pasado la hora', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $entrada = fichajeDeDescarte('2026-03-14T07:00:00Z');

    // Diez intentos en 59 minutos: uno menos que el borde.
    sembrarFallos($escenario['device'], $entrada['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 59);

    $respuesta = loteDeDescarte($escenario, [$entrada]);

    $respuesta->assertStatus(207)->assertValidResponse();

    expect($respuesta->json('results.0.status'))->toBe(503)
        ->and($respuesta->json('results.0.outcome.type'))->toBe('urn:kronoqr:problem:scan-not-processed')
        ->and(DB::table('scan_events')->count())->toBe(0)
        ->and(DB::table('discarded_scan_reports')->count())->toBe(0);
})->group('RN-22', 'RN-21');

it('no descarta con el cupo del quiosco agotado y avisa de ello', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $entrada = fichajeDeDescarte('2026-03-14T07:00:00Z');

    sembrarFallos($escenario['device'], $entrada['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 61);
    sembrarDescartesDelServidor($escenario['device'], 3);

    $respuesta = loteDeDescarte($escenario, [$entrada]);

    $respuesta->assertStatus(207)->assertValidResponse();

    expect($respuesta->json('results.0.status'))->toBe(503)
        ->and(DB::table('scan_events')->count())->toBe(0)
        ->and($escenario['metrics']->budgetExhausted)->toBe([$escenario['deviceUuid']])
        ->and($escenario['metrics']->serverDiscarded)->toBe([]);
})->group('RN-22', 'RN-21');

it('no cuenta una dependencia caida aunque falle cien veces', function (): void {
    $escenario = escenarioDeDescarte();

    app()->instance(SiteCalendar::class, new class implements SiteCalendar
    {
        public function timezoneOf(int $siteId): ?DateTimeZone
        {
            return null;
        }

        public function timezoneForRecording(int $siteId): ?DateTimeZone
        {
            $driver = new PDOException('SQLSTATE[08006]: connection failure');
            $driver->errorInfo = ['08006', 7, 'connection failure'];

            throw new QueryException('pgsql', 'select timezone from sites where id = ?', [$siteId], $driver);
        }
    });

    $entrada = fichajeDeDescarte('2026-03-14T07:00:00Z');
    sembrarFallos($escenario['device'], $entrada['scan_id'], hash('sha256', DESCARTE_TARJETA), 20, 120);

    loteDeDescarte($escenario, [$entrada])->assertStatus(207)->assertValidResponse();

    // Ni un apunte mas: la caida de ADR-059 no es un veneno.
    expect(DB::table('scan_processing_failures')->where('scan_id', $entrada['scan_id'])->count())->toBe(20)
        ->and(DB::table('scan_events')->count())->toBe(0);
})->group('RN-22', 'RNF-D-03');

it('no suma los intentos que llegan de otro quiosco con el mismo scan_id', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $entrada = fichajeDeDescarte('2026-03-14T07:00:00Z');
    $otro = AttendanceFixtures::device($escenario['site'], 'Spa');

    // La historia la abrio OTRO quiosco: los intentos de este no la empujan.
    sembrarFallos($otro['id'], $entrada['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 61);

    $respuesta = loteDeDescarte($escenario, [$entrada]);

    expect($respuesta->json('results.0.status'))->toBe(503)
        ->and(DB::table('scan_processing_failures')->where('scan_id', $entrada['scan_id'])->count())->toBe(10)
        ->and(DB::table('scan_events')->count())->toBe(0);
})->group('RN-22', 'RS-03');

// --- /scan: el 500 de siempre hasta el umbral, despues el 422 -----------------

it('responde 500 por /scan dentro de la ventana y 422 scan-discarded al cumplirla', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $escaneo = fichajeDeDescarte('2026-03-14T07:00:00Z');

    sembrarFallos($escenario['device'], $escaneo['scan_id'], hash('sha256', DESCARTE_TARJETA), 8, 61);

    // Noveno intento: 500, como antes de ADR-061.
    escaneoSueltoDeDescarte($escenario, $escaneo)->assertStatus(500);

    // Decimo: descartado.
    $respuesta = escaneoSueltoDeDescarte($escenario, $escaneo);

    $respuesta->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertValidRequest()
        ->assertValidResponse();

    expect($respuesta->json())->toBe([
        'type' => DESCARTE_TIPO,
        'title' => 'Escaneo descartado',
        'status' => 422,
        'detail' => 'El escaneo no se ha podido procesar y queda pendiente de revision.',
        'scan_id' => $escaneo['scan_id'],
    ])->and(DB::table('scan_events')->where('scan_id', $escaneo['scan_id'])->value('result'))->toBe('discarded_unprocessable');
})->group('RN-22', 'RF-AT-01');

// --- Idempotencia: el reenvio repite el descarte por las tres rutas -----------

it('repite el mismo 422 byte a byte por /scan y por el lote sin volver a procesar, aunque el defecto se haya arreglado', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $escaneo = fichajeDeDescarte('2026-03-14T07:00:00Z');
    sembrarFallos($escenario['device'], $escaneo['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 61);

    $original = escaneoSueltoDeDescarte($escenario, $escaneo);
    $original->assertStatus(422);

    // Se arregla el defecto: un reenvio tardio NO puede registrar el fichaje,
    // o RRHH y el reenvio crearian un tramo doble.
    curarCalendario($escenario);
    FrozenTime::at('2026-03-14 12:00:00');

    $reenvio = escaneoSueltoDeDescarte($escenario, $escaneo);
    $lote = loteDeDescarte($escenario, [$escaneo]);

    $reenvio->assertStatus(422)->assertValidResponse();
    $lote->assertStatus(207)->assertValidResponse();

    expect($reenvio->getContent())->toBe($original->getContent())
        ->and($lote->json('results.0.status'))->toBe(422)
        ->and($lote->json('results.0.outcome'))->toBe($original->json())
        ->and(DB::table('shift_entries')->count())->toBe(0)
        ->and(DB::table('scan_events')->count())->toBe(1)
        ->and(DB::table('discarded_scan_reports')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'scan.discarded')->count())->toBe(1);
})->group('RN-22', 'RF-AT-07');

it('repite el descarte aunque el defecto siga y el reenvio falle antes de llegar al UNIQUE', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $escaneo = fichajeDeDescarte('2026-03-14T07:00:00Z');
    sembrarFallos($escenario['device'], $escaneo['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 61);

    $original = escaneoSueltoDeDescarte($escenario, $escaneo);
    $reenvio = escaneoSueltoDeDescarte($escenario, $escaneo);

    expect($reenvio->getStatusCode())->toBe(422)
        ->and($reenvio->getContent())->toBe($original->getContent())
        // El reenvio no suma un intento mas: ya estaba decidido.
        ->and(DB::table('scan_processing_failures')->where('scan_id', $escaneo['scan_id'])->count())->toBe(10);
})->group('RN-22', 'RF-AT-07');

it('acusa sin segunda fila el aviso posterior del quiosco por /scan/discarded', function (): void {
    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $escaneo = fichajeDeDescarte('2026-03-14T07:00:00Z');
    sembrarFallos($escenario['device'], $escaneo['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 61);

    escaneoSueltoDeDescarte($escenario, $escaneo)->assertStatus(422);

    // El quiosco 2.2.x trata el 422 desconocido como RN-22: lo avisa.
    Api::as($escenario['token'])
        ->post('/api/v1/scan/discarded', ['reports' => [[
            'scan_id' => $escaneo['scan_id'],
            'occurred_at' => $escaneo['occurred_at'],
            'kind' => 'qr',
            'qr_payload' => DESCARTE_TARJETA,
            'http_status' => 422,
            'problem_type' => DESCARTE_TIPO,
            'discarded_at' => '2026-03-14T08:00:05Z',
        ]]])
        ->assertStatus(200)
        ->assertJsonPath('acknowledged', [$escaneo['scan_id']]);

    expect(DB::table('discarded_scan_reports')->count())->toBe(1)
        ->and(DB::table('discarded_scan_reports')->value('source'))->toBe('server');
})->group('RN-22');

// --- /scan/pin -----------------------------------------------------------------

it('descarta por /scan/pin con la misma regla y el mismo cuerpo', function (): void {
    $escenario = escenarioDeDescarte();

    EmployeePins::issue($escenario['employee'], DESCARTE_PIN);
    $codigo = EmployeePins::codeOf($escenario['employee']);
    $sobre = EmployeePins::seal(DESCARTE_PIN, EmployeePins::configureSealing());

    envenenarCalendario();

    $scanId = Str::uuid7()->toString();
    $cuerpo = [
        'scan_id' => $scanId,
        'occurred_at' => '2026-03-14T07:00:00Z',
        'employee_code' => $codigo,
        'pin_sealed' => $sobre,
    ];

    // La misma huella que calcula el guardian: el codigo y el MISMO sobre que
    // la tablet reenvia.
    sembrarFallos($escenario['device'], $scanId, hash('sha256', 'pin|'.$codigo.'|'.$sobre), 9, 61, 'pin_kiosk');

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo);

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('type'))->toBe(DESCARTE_TIPO)
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('origin'))->toBe('pin_kiosk')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('payload_fingerprint'))->toBeNull()
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $scanId)->value('attribution'))->toBe('employee_code');

    // El reenvio no vuelve a comprobar el PIN (PIN-02) y repite el descarte.
    $reenvio = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', $cuerpo);

    expect($reenvio->getContent())->toBe($respuesta->getContent());
})->group('RN-22', 'RF-AT-11', 'RF-AT-07');

// --- RS-03: el descarte se rellena hasta el suelo de tiempo -------------------

it('rellena el descarte hasta el doble del suelo de RS-03', function (): void {
    config(['security.rejection_floor_ms' => 40]);

    $escenario = escenarioDeDescarte();
    envenenarCalendario();

    $escaneo = fichajeDeDescarte('2026-03-14T07:00:00Z');
    sembrarFallos($escenario['device'], $escaneo['scan_id'], hash('sha256', DESCARTE_TARJETA), 9, 61);

    $inicio = hrtime(true);
    escaneoSueltoDeDescarte($escenario, $escaneo)->assertStatus(422);
    $milisegundos = (hrtime(true) - $inicio) / 1_000_000;

    expect($milisegundos)->toBeGreaterThanOrEqual(80.0);
})->group('RN-22', 'RS-03');
