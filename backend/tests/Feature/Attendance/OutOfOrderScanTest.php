<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Infrastructure\Persistence\EloquentWorkDayRepository;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Attendance\StaleOpenWorkDayRepository;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * RN-18, el **fichaje irreconciliable**, desde los tres endpoints de escritura
 * del quiosco y validado contra `docs/api/openapi.yaml` en cada respuesta
 * (doc 02 §9.5, nivel «feature + contrato»).
 *
 * Es el Gherkin del doc 01 §11 «Fichaje irreconciliable en un lote de la cola
 * offline», mas los bordes que ese escenario no fija: el limite exacto, el
 * reenvio idempotente y quien gana entre el anti-rebote y esta regla.
 *
 * LO QUE ESTA PRUEBA VIGILA DE VERDAD. Que un escaneo que **no puede** cuadrar
 * con el registro (1) deje fila, (2) responda `422` y no `503`, y (3) responda
 * un `422` **indistinguible** del de una tarjeta revocada. Lo primero es la
 * regla dura 19 —un fichaje real de una persona no se pierde—, lo segundo es lo
 * que vacia la cola del quiosco (ADR-012: las tablets desplegadas solo borran
 * ante 200 y 422) y lo tercero es RS-03 y la regla dura 17.
 *
 * **El reloj esta detenido** (regla dura 2, ADR-021) y la credencial la resuelve
 * un doble, por lo mismo que en `RegisterScanTest`: aqui se prueba que un
 * escaneo imposible se registra, no que el HMAC verifique.
 */

uses(RefreshDatabase::class);

const TARJETA_FUERA_DE_ORDEN = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const TARJETA_REVOCADA_FUERA_DE_ORDEN = 'FH1.a3.0000000000000000000000.0000000000000000';

const PIN_FUERA_DE_ORDEN = '481902';

/** El lote llega a las 18:00; los fichajes son de la tarde. */
const SINCRONIZADO_FUERA_DE_ORDEN = '2026-03-14 18:00:00';

/**
 * La entrada del turno que el empleado tiene abierto. Es el limite de RN-18.
 *
 * **Con sufijo**, como las demas de este fichero: las constantes de un fichero
 * de Pest son globales, y un nombre generico choca con el de otra suite en
 * cuanto un filtro mete las dos en el mismo proceso (ver `RegisterScanTest`).
 */
const ENTRADA_ABIERTA_FUERA_DE_ORDEN = '2026-03-14T14:00:00Z';

/**
 * Escenario con el turno de las 14:00 **ya abierto**, como en el Gherkin.
 *
 * El tramo se abre fichando de verdad y no insertandolo a mano: lo que RN-18
 * compara es la entrada del turno abierto, y una fila escrita por fuera podria
 * no ser la que el agregado carga.
 *
 * Los dos parametros existen para el turno de noche, que necesita otra entrada
 * y otro «ahora»; el resto de pruebas usa los de serie.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, code: string, publicKey: string, metrics: RecordingScanMetrics}
 */
function escenarioConTurnoAbierto(
    string $entrada = ENTRADA_ABIERTA_FUERA_DE_ORDEN,
    string $ahora = SINCRONIZADO_FUERA_DE_ORDEN,
): array {
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_FUERA_DE_ORDEN);

    FrozenTime::at($ahora);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(TARJETA_FUERA_DE_ORDEN, $escenario['employee'])
            ->rejecting(TARJETA_REVOCADA_FUERA_DE_ORDEN, CredentialRejectionReason::REVOKED),
    );

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    $completo = [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
        'metrics' => $metricas,
    ];

    fichar($completo, Str::uuid7()->toString(), $entrada)
        ->assertOk()
        ->assertJsonPath('action', 'clock_in');

    return $completo;
}

/**
 * @param  array{token: string, ...}  $escenario
 * @return TestResponse<Response>
 */
function fichar(array $escenario, string $scanId, string $occurredAt): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => TARJETA_FUERA_DE_ORDEN,
        ]);
}

/**
 * @param  array{token: string, ...}  $escenario
 * @param  list<array<string, mixed>>  $scans
 * @return TestResponse<Response>
 */
function sincronizarFueraDeOrden(array $escenario, array $scans): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', ['scans' => $scans]);
}

/**
 * @return array{scan_id: string, occurred_at: string, qr_payload: string}
 */
function encolado(string $occurredAt, string $payload = TARJETA_FUERA_DE_ORDEN): array
{
    return [
        'scan_id' => Str::uuid7()->toString(),
        'occurred_at' => $occurredAt,
        'qr_payload' => $payload,
    ];
}

/**
 * El anti-rebote apagado: 0 es un valor legitimo de la clave (RF-AT-06).
 *
 * **Se llama antes del primer fichaje de la prueba.** `OperationalSettingsProvider`
 * esta enlazado como `scoped()` y memoriza lo resuelto; en una prueba de feature
 * las peticiones comparten contenedor, asi que cambiar el ajuste despues de la
 * primera dejaria a las siguientes viendo los 60 s de serie.
 */
function sinAntiRebote(): void
{
    DB::table('installation_settings')->updateOrInsert(
        ['key' => 'ATTENDANCE_DEBOUNCE_SECONDS'],
        ['value' => '0', 'updated_at' => '2026-01-01 00:00:00+00'],
    );

    app()->forgetScopedInstances();
}

// --- El escaneo suelto -------------------------------------------------------

it('registra el escaneo anterior a la entrada del turno abierto y responde 422', function (): void {
    // El corazon de RN-18. Turno abierto a las 14:00 y llega una salida de las
    // 13:50: no puede producir tramo —RN-03 exige salida estrictamente
    // posterior— y no se va a arreglar reintentando.
    $escenario = escenarioConTurnoAbierto();
    $scanId = Str::uuid7()->toString();

    $respuesta = fichar($escenario, $scanId, '2026-03-14T13:50:00Z');

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($evento?->result)->toBe('rejected_out_of_order')
        // Sin tramo (`scan_events_chk_rejected_has_no_shift_entry`) y sin
        // acumulado (`scan_events_chk_worked_minutes`): la respuesta es generica
        // y no hay nada que reconstruir.
        ->and($evento?->shift_entry_id)->toBeNull()
        ->and($evento?->worked_minutes)->toBeNull()
        // Y marcado: es lo que la revision diaria lee para abrir la incidencia.
        ->and($evento?->flagged_for_review)->toBeTrue();
})->group('RN-18', 'RF-AT-09', 'RQ-06');

it('deja el turno abierto exactamente como estaba', function (): void {
    // «Y el tramo abierto de las 14:00 sigue abierto e intacto» (doc 01 §11). Un
    // escaneo irreconciliable no anula lo que el empleado si ficho.
    $escenario = escenarioConTurnoAbierto();

    fichar($escenario, Str::uuid7()->toString(), '2026-03-14T13:50:00Z')->assertStatus(422);

    $tramo = DB::table('shift_entries')->first();

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and($tramo?->status)->toBe('open')
        ->and($tramo?->clocked_out_at)->toBeNull()
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RN-01');

it('no distingue el fichaje irreconciliable de una tarjeta revocada', function (): void {
    // Regla dura 17 y RS-03: el cuerpo es el mismo `ScanRejected`, campo por
    // campo. La unica diferencia legitima es el `scan_id`, que es el eco de la
    // peticion.
    $escenario = escenarioConTurnoAbierto();

    $imposible = Str::uuid7()->toString();
    $revocada = Str::uuid7()->toString();

    $unoDeRN18 = fichar($escenario, $imposible, '2026-03-14T13:50:00Z');

    $otro = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $revocada])
        ->post('/api/v1/scan', [
            'scan_id' => $revocada,
            'occurred_at' => '2026-03-14T16:00:00Z',
            'qr_payload' => TARJETA_REVOCADA_FUERA_DE_ORDEN,
        ]);

    $unoDeRN18->assertStatus(422)->assertValidResponse();
    $otro->assertStatus(422)->assertValidResponse();

    /** @var array<string, mixed> $cuerpoRN18 */
    $cuerpoRN18 = $unoDeRN18->json();
    /** @var array<string, mixed> $cuerpoRevocada */
    $cuerpoRevocada = $otro->json();

    expect(array_keys($cuerpoRN18))->toBe(array_keys($cuerpoRevocada))
        ->and($cuerpoRN18['type'])->toBe($cuerpoRevocada['type'])
        ->and($cuerpoRN18['title'])->toBe($cuerpoRevocada['title'])
        ->and($cuerpoRN18['status'])->toBe($cuerpoRevocada['status'])
        ->and($cuerpoRN18['detail'])->toBe($cuerpoRevocada['detail'])
        // Y el motivo real solo existe del lado del servidor.
        ->and(DB::table('scan_events')->where('scan_id', $imposible)->value('result'))->toBe('rejected_out_of_order')
        ->and(DB::table('scan_events')->where('scan_id', $revocada)->value('result'))->toBe('rejected_revoked');
})->group('RN-18', 'RS-03');

// --- El limite exacto --------------------------------------------------------

it('rechaza el escaneo del mismo instante que la entrada y acepta el segundo siguiente', function (): void {
    // El limite de RN-18 es el de RN-03 leido al reves: la salida tiene que ser
    // **estrictamente** posterior. La igualdad daria un tramo de duracion cero,
    // que no es representable, asi que 14:00:00 es irreconciliable y 14:00:01 no.
    //
    // El anti-rebote se apaga para que el borde que se mide sea el de esta regla
    // y no el de RF-AT-06: con los 60 s de serie, un escaneo un segundo despues
    // de la entrada seria un reenvio suprimido y no probaria nada de RN-18.
    sinAntiRebote();
    $escenario = escenarioConTurnoAbierto();

    $enElLimite = fichar($escenario, Str::uuid7()->toString(), '2026-03-14T14:00:00Z');
    $enElLimite->assertStatus(422)->assertValidResponse();

    $unSegundoDespues = fichar($escenario, Str::uuid7()->toString(), '2026-03-14T14:00:01Z');
    $unSegundoDespues->assertOk()->assertValidResponse();

    expect($unSegundoDespues->json('action'))->toBe('clock_out')
        ->and(DB::table('shift_entries')->first()?->clocked_out_at)->not->toBeNull()
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(1);
})->group('RN-18', 'RN-03');

it('deja ganar al anti-rebote dentro de su ventana', function (): void {
    // Orden de evaluacion (decision 2 de la ficha): RF-AT-06 se evalua ANTES que
    // RN-18. Un reenvio de la propia entrada, 30 s antes de ella y dentro de la
    // ventana de 60 s, sigue siendo anti-rebote —un `200` con `action:
    // debounced`— y no un fichaje irreconciliable. Si se invirtiera el orden, la
    // tablet que reintenta un fichaje ya aceptado se llevaria un rechazo.
    $escenario = escenarioConTurnoAbierto();

    $respuesta = fichar($escenario, Str::uuid7()->toString(), '2026-03-14T13:59:30Z');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('debounced')
        ->and(DB::table('scan_events')->where('result', 'rejected_debounce')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(0);
})->group('RN-18', 'RF-AT-06');

// --- Idempotencia ------------------------------------------------------------

it('devuelve el mismo 422 al reenviar el mismo scan_id y no duplica la fila', function (): void {
    // Regla dura 8: un reenvio devuelve la respuesta original, tambien cuando la
    // original fue un rechazo. Sin esto, la cola offline volveria a escribir la
    // misma fila con cada reintento y la bandeja se llenaria de la misma
    // incidencia.
    $escenario = escenarioConTurnoAbierto();
    $scanId = Str::uuid7()->toString();

    $primera = fichar($escenario, $scanId, '2026-03-14T13:50:00Z');
    $segunda = fichar($escenario, $scanId, '2026-03-14T13:50:00Z');

    $primera->assertStatus(422)->assertValidResponse();
    $segunda->assertStatus(422)->assertValidResponse();

    expect($segunda->json())->toBe($primera->json())
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RN-18', 'RF-AT-07');

// --- El lote de la cola offline ---------------------------------------------

it('registra los demas elementos del lote y saca el imposible con un 422', function (): void {
    // El Gherkin completo. La cola trae la salida imposible de las 13:50 y dos
    // fichajes que si cuadran; el lote se procesa por `occurred_at` y el
    // elemento que no puede cuadrar no arrastra a los otros (regla dura 19).
    $escenario = escenarioConTurnoAbierto();

    $imposible = encolado('2026-03-14T13:50:00Z');
    $salida = encolado('2026-03-14T22:00:00Z');
    $entradaSiguiente = encolado('2026-03-15T06:00:00Z');

    $respuesta = sincronizarFueraDeOrden($escenario, [$entradaSiguiente, $imposible, $salida]);

    $respuesta->assertStatus(207)->assertValidRequest()->assertValidResponse();

    // En orden de `occurred_at`, que es como se procesan.
    expect($respuesta->json('results.0.scan_id'))->toBe($imposible['scan_id'])
        ->and($respuesta->json('results.0.status'))->toBe(422)
        // **Y no 503**: lo que vacia la cola del quiosco.
        ->and($respuesta->json('results.0.outcome.type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and($respuesta->json('results.1.scan_id'))->toBe($salida['scan_id'])
        ->and($respuesta->json('results.1.status'))->toBe(200)
        ->and($respuesta->json('results.1.outcome.action'))->toBe('clock_out')
        ->and($respuesta->json('results.2.scan_id'))->toBe($entradaSiguiente['scan_id'])
        ->and($respuesta->json('results.2.status'))->toBe(200)
        ->and($respuesta->json('results.2.outcome.action'))->toBe('clock_in');

    expect(DB::table('scan_events')->count())->toBe(4)
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(2)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RF-KI-04', 'RQ-06');

// --- El fichaje por PIN ------------------------------------------------------

it('responde igual por el camino del PIN', function (): void {
    // RF-AT-11: el PIN identifica a la persona por otro camino y a partir de ahi
    // hace exactamente lo mismo. Si RN-18 viviera en el controlador y no en el
    // caso de uso, este endpoint se lo saltaria.
    $escenario = escenarioConTurnoAbierto();
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T13:50:00Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal(PIN_FUERA_DE_ORDEN, $escenario['publicKey']),
        ]);

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($evento?->result)->toBe('rejected_out_of_order')
        ->and($evento?->origin)->toBe('pin_kiosk')
        ->and($evento?->shift_entry_id)->toBeNull()
        ->and($evento?->worked_minutes)->toBeNull()
        ->and($evento?->flagged_for_review)->toBeTrue();
})->group('RN-18', 'RF-AT-11');

// --- El turno de noche -------------------------------------------------------

it('compara contra el turno abierto aunque su jornada no sea el dia civil del escaneo', function (): void {
    // Regla dura 4 y RN-05 llevadas a RN-18. La entrada de las 23:30 UTC son las
    // 00:30 en Madrid, asi que el turno abierto pertenece a la jornada del **15**;
    // el escaneo de las 22:50 UTC son las 23:50 del **14**. Son dos dias civiles
    // distintos.
    //
    // Lo que esto protege: que el agregado se cargue con `findOpenWorkDayFor()`
    // —«el turno que esta abierto, sea de la jornada que sea»— y no por la fecha
    // del escaneo. Buscando por fecha no se veria ningun turno abierto, el
    // escaneo se resolveria como una entrada nueva y el desenlace no seria un
    // `422` con su fila, sino un choque contra RN-01 en la base de datos.
    $escenario = escenarioConTurnoAbierto('2026-03-14T23:30:00Z', '2026-03-15 06:00:00');
    $scanId = Str::uuid7()->toString();

    $respuesta = fichar($escenario, $scanId, '2026-03-14T22:50:00Z');

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();
    $tramo = DB::table('shift_entries')->first();

    expect($evento?->result)->toBe('rejected_out_of_order')
        ->and($evento?->shift_entry_id)->toBeNull()
        ->and($evento?->flagged_for_review)->toBeTrue()
        // El turno sigue abierto y en SU jornada, la del dia siguiente en la zona
        // del centro (RN-05, ADR-006).
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and($tramo?->status)->toBe('open')
        ->and($tramo?->work_date)->toBe('2026-03-15')
        ->and($tramo?->clocked_out_at)->toBeNull();
})->group('RN-18', 'RN-05', 'RN-01');

// --- La salvaguarda de la carrera, sin depender del azar ---------------------

it('no convierte en irreconciliable al perdedor de una carrera del cambio de turno', function (): void {
    // La carrera de `ScanIdempotencyConcurrencyTest`, pero **determinista**: el
    // repositorio responde «no hay turno abierto» a la primera consulta —la que
    // decide si el escaneo abre o cierra— y dice la verdad a partir de la
    // segunda. Es exactamente lo que pasa cuando el escaneo de otra tablet
    // confirma entre las dos lecturas del mismo caso de uso.
    //
    // Lo que se afirma: ese escaneo **no** se registra como
    // `rejected_out_of_order`. Choca contra RN-01 al abrir, el caso de uso
    // reintenta y en el segundo intento sale por donde tiene que salir. Si el
    // camino de apertura de RN-18 mirase tambien los tramos ABIERTOS —y no solo
    // los cerrados—, quien pasa la tarjeta a la vez que su companero se llevaria
    // un rechazo (regla dura 19).
    $escenario = escenarioConTurnoAbierto();

    app()->instance(
        WorkDayRepository::class,
        new StaleOpenWorkDayRepository(app(EloquentWorkDayRepository::class)),
    );

    $scanId = Str::uuid7()->toString();
    $respuesta = fichar($escenario, $scanId, '2026-03-14T14:00:30Z');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('debounced')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('rejected_debounce')
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(0)
        // Y el tramo del ganador sigue abierto y es el unico.
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->first()?->status)->toBe('open');
})->group('RN-18', 'RN-01', 'RF-AT-06', 'RQ-03');

it('sigue cerrando el tramo tras la carrera cuando el anti-rebote ya no alcanza', function (): void {
    // La misma lectura vieja, pero con el escaneo fuera de la ventana de gracia:
    // aqui el segundo intento **si** tiene que cerrar el tramo. Sin esta, la
    // prueba de arriba se cumpliria igual con un caso de uso que respondiera
    // siempre «anti-rebote» y nunca cerrara ninguna jornada.
    $escenario = escenarioConTurnoAbierto();

    app()->instance(
        WorkDayRepository::class,
        new StaleOpenWorkDayRepository(app(EloquentWorkDayRepository::class)),
    );

    $scanId = Str::uuid7()->toString();
    $respuesta = fichar($escenario, $scanId, '2026-03-14T17:30:00Z');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_out')
        ->and($respuesta->json('worked_minutes'))->toBe(210)
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(0)
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->first()?->status)->toBe('closed')
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RN-01', 'RQ-03');

// --- El camino de APERTURA: la entrada que pisa un tramo ya cerrado ----------

/**
 * Escenario con una jornada **ya cerrada** de 09:00 a 13:00 y sin ningun turno
 * abierto, que es el otro borde de RN-18 (RN-02).
 *
 * La jornada se ficha de verdad, entrada y salida, por lo mismo que en el
 * escenario del turno abierto: lo que la regla compara son los tramos que el
 * agregado carga.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, code: string, publicKey: string, metrics: RecordingScanMetrics}
 */
function escenarioConTramoCerrado(): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_FUERA_DE_ORDEN);

    FrozenTime::at(SINCRONIZADO_FUERA_DE_ORDEN);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(TARJETA_FUERA_DE_ORDEN, $escenario['employee'])
            ->rejecting(TARJETA_REVOCADA_FUERA_DE_ORDEN, CredentialRejectionReason::REVOKED),
    );

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    $completo = [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
        'metrics' => $metricas,
    ];

    fichar($completo, Str::uuid7()->toString(), '2026-03-14T09:00:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'clock_in');

    fichar($completo, Str::uuid7()->toString(), '2026-03-14T13:00:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'clock_out');

    return $completo;
}

it('rechaza la entrada anterior a un tramo ya cerrado y no toca nada', function (): void {
    // La otra mitad de RN-18, la que gobierna RN-02: el tramo que esta entrada
    // abriria **no tiene fin**, asi que pisaria al de 09:00 a 13:00 —y a
    // cualquier cosa que viniera despues—. La restriccion de exclusion lo
    // rechazaba en la base de datos con un `500` y sin dejar fila; ahora deja
    // fila, responde el `422` generico y abre incidencia.
    $escenario = escenarioConTramoCerrado();
    $scanId = Str::uuid7()->toString();

    $respuesta = fichar($escenario, $scanId, '2026-03-14T08:00:00Z');

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();
    $tramo = DB::table('shift_entries')->first();

    expect($evento?->result)->toBe('rejected_out_of_order')
        ->and($evento?->shift_entry_id)->toBeNull()
        ->and($evento?->worked_minutes)->toBeNull()
        ->and($evento?->flagged_for_review)->toBeTrue()
        // Y la jornada de verdad no se toca: sigue siendo un tramo cerrado de
        // cuatro horas.
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and($tramo?->status)->toBe('closed')
        ->and($tramo?->duration_minutes)->toBe(240)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RN-02');

it('rechaza la entrada que cae dentro del tramo ya cerrado', function (): void {
    // El caso intermedio: las 11:00 estan dentro de [09:00, 13:00). No hace
    // falta que la entrada sea anterior al tramo para pisarlo.
    $escenario = escenarioConTramoCerrado();
    $scanId = Str::uuid7()->toString();

    fichar($escenario, $scanId, '2026-03-14T11:00:00Z')->assertStatus(422)->assertValidResponse();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('rejected_out_of_order')
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RN-18', 'RN-02');

it('deja abrir un tramo nuevo justo en la salida del anterior', function (): void {
    // El limite exacto del camino de apertura, y es el **contrario** al del
    // cierre: la restriccion de exclusion usa `[inicio, fin)`, asi que entrar a
    // las 13:00 en punto —la hora a la que se salio— no solapa. Volver de comer
    // justo cuando se ficho la salida es legitimo, y esta prueba es lo que
    // impide que alguien «arregle» RN-18 con un `>=`.
    //
    // El anti-rebote se apaga por lo mismo que en el limite del cierre: a los
    // cero segundos de la salida, RF-AT-06 contestaria primero —y con razon— y
    // el borde que aqui se mide no llegaria a evaluarse.
    sinAntiRebote();
    $escenario = escenarioConTramoCerrado();
    $scanId = Str::uuid7()->toString();

    $respuesta = fichar($escenario, $scanId, '2026-03-14T13:00:00Z');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('clock_in')
        ->and(DB::table('shift_entries')->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('status', 'open')->count())->toBe(1);
})->group('RN-18', 'RN-02');

it('resuelve igual una vuelta de pausa que pisaria el tramo cerrado', function (): void {
    // ADR-024: una vuelta de pausa tambien **abre** tramo, asi que le toca la
    // misma mitad de RN-18. Se declara la intencion `break_end` para que no haya
    // duda de por que camino entro.
    $escenario = escenarioConTramoCerrado();
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T10:30:00Z',
            'qr_payload' => TARJETA_FUERA_DE_ORDEN,
            'intent' => 'break_end',
        ]);

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('rejected_out_of_order')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('intent'))->toBe('break_end')
        ->and(DB::table('shift_entries')->count())->toBe(1);
})->group('RN-18', 'RN-02', 'RF-AT-12');

it('saca del lote la entrada que pisa un tramo ya fichado en otro quiosco', function (): void {
    // El caso real: la persona ya ficho su jornada en el quiosco de recepcion y
    // en la cola de otro quiosco quedaba una entrada vieja de las 08:00 que
    // nunca llego a enviarse. Ese elemento no puede cuadrar y sale con `422`;
    // los otros dos del lote se registran (regla dura 19).
    $escenario = escenarioConTramoCerrado();

    $imposible = encolado('2026-03-14T08:00:00Z');
    $entradaDeTarde = encolado('2026-03-14T15:00:00Z');
    $salidaDeTarde = encolado('2026-03-14T20:00:00Z');

    $respuesta = sincronizarFueraDeOrden($escenario, [$salidaDeTarde, $imposible, $entradaDeTarde]);

    $respuesta->assertStatus(207)->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('results.0.scan_id'))->toBe($imposible['scan_id'])
        ->and($respuesta->json('results.0.status'))->toBe(422)
        ->and($respuesta->json('results.0.outcome.type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and($respuesta->json('results.1.status'))->toBe(200)
        ->and($respuesta->json('results.1.outcome.action'))->toBe('clock_in')
        ->and($respuesta->json('results.2.status'))->toBe(200)
        ->and($respuesta->json('results.2.outcome.action'))->toBe('clock_out')
        // Dos tramos cerrados: el de la manana y el de la tarde.
        ->and(DB::table('shift_entries')->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('status', 'closed')->count())->toBe(2)
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RN-02', 'RF-KI-04');

// --- RN-02 a traves de jornadas: el turno de noche ya cerrado ----------------

/**
 * Escenario con un **turno de noche cerrado**: entrada el 14 a las 22:00 UTC y
 * salida el 15 a las 06:00 UTC, todo en la jornada del **14** (RN-05, regla
 * dura 4).
 *
 * Es el borde que el agregado no puede ver por si solo: quien fiche a las 02:00
 * del 15 carga la jornada del 15, que esta vacia.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, code: string, publicKey: string, metrics: RecordingScanMetrics}
 */
function escenarioConTurnoDeNocheCerrado(): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_FUERA_DE_ORDEN);

    FrozenTime::at('2026-03-15 12:00:00');
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(TARJETA_FUERA_DE_ORDEN, $escenario['employee'])
            ->rejecting(TARJETA_REVOCADA_FUERA_DE_ORDEN, CredentialRejectionReason::REVOKED),
    );

    $metricas = new RecordingScanMetrics;
    app()->instance(ScanMetrics::class, $metricas);

    Spectator::using('openapi.yaml');

    $completo = [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
        'metrics' => $metricas,
    ];

    fichar($completo, Str::uuid7()->toString(), '2026-03-14T22:00:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'clock_in');

    fichar($completo, Str::uuid7()->toString(), '2026-03-15T06:00:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'clock_out');

    // El turno entero pertenece a la jornada del 14: si esto dejara de ser
    // cierto, el escenario ya no probaria «a traves de jornadas».
    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->first()?->work_date)->toBe('2026-03-14');

    return $completo;
}

it('rechaza la entrada que cae dentro de un turno de noche de la jornada anterior', function (): void {
    // El hueco que RN-18 tenia por el otro lado. La jornada del 15 esta vacia,
    // asi que el agregado no ve nada; RN-02 es por EMPLEADO y a traves de
    // jornadas, y el tramo de 22:00 a 06:00 sigue vivo a las 02:00. Antes esto
    // llegaba a `clockIn()`, la restriccion de exclusion lo abortaba, el caso de
    // uso reintentaba tres veces y el elemento salia como `503`: la cola lo
    // reintentaba para siempre y no quedaba ni una linea.
    $escenario = escenarioConTurnoDeNocheCerrado();
    $scanId = Str::uuid7()->toString();

    $respuesta = fichar($escenario, $scanId, '2026-03-15T02:00:00Z');

    $respuesta->assertStatus(422)->assertValidRequest()->assertValidResponse();

    $evento = DB::table('scan_events')->where('scan_id', $scanId)->first();
    $tramo = DB::table('shift_entries')->first();

    expect($evento?->result)->toBe('rejected_out_of_order')
        ->and($evento?->shift_entry_id)->toBeNull()
        ->and($evento?->worked_minutes)->toBeNull()
        ->and($evento?->flagged_for_review)->toBeTrue()
        // El turno de noche, intacto: ocho horas, cerrado y en su jornada.
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and($tramo?->status)->toBe('closed')
        ->and($tramo?->duration_minutes)->toBe(480)
        ->and($tramo?->work_date)->toBe('2026-03-14')
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RN-02', 'RN-05');

it('deja fichar la entrada del dia siguiente justo cuando termino el turno de noche', function (): void {
    // El limite, tambien a traves de jornadas: `[inicio, fin)`. Entrar a las
    // 06:00 en punto —la hora a la que se salio— no solapa, y tiene que abrir
    // tramo. El anti-rebote se apaga por lo mismo que en los otros limites.
    sinAntiRebote();
    $escenario = escenarioConTurnoDeNocheCerrado();
    $scanId = Str::uuid7()->toString();

    $respuesta = fichar($escenario, $scanId, '2026-03-15T06:00:00Z');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and($respuesta->json('work_date'))->toBe('2026-03-15')
        ->and(DB::table('shift_entries')->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('status', 'open')->count())->toBe(1)
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(0);
})->group('RN-18', 'RN-02', 'RN-05');

it('saca del lote la entrada que cae dentro del turno de noche ya fichado', function (): void {
    // El caso real y completo: la tablet drena a mediodia una entrada de las
    // 02:00 que nunca se envio, junto con la jornada de tarde de esa persona. El
    // elemento imposible sale con `422` —el quiosco lo descarta— y los otros dos
    // se registran (regla dura 19, ADR-012).
    $escenario = escenarioConTurnoDeNocheCerrado();

    $imposible = encolado('2026-03-15T02:00:00Z');
    $entrada = encolado('2026-03-15T14:00:00Z');
    $salida = encolado('2026-03-15T20:00:00Z');

    $respuesta = sincronizarFueraDeOrden($escenario, [$salida, $imposible, $entrada]);

    $respuesta->assertStatus(207)->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('results.0.scan_id'))->toBe($imposible['scan_id'])
        ->and($respuesta->json('results.0.status'))->toBe(422)
        ->and($respuesta->json('results.0.outcome.type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and($respuesta->json('results.1.status'))->toBe(200)
        ->and($respuesta->json('results.1.outcome.action'))->toBe('clock_in')
        ->and($respuesta->json('results.2.status'))->toBe(200)
        ->and($respuesta->json('results.2.outcome.action'))->toBe('clock_out')
        ->and(DB::table('shift_entries')->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('status', 'closed')->count())->toBe(2)
        ->and(DB::table('scan_events')->where('result', 'rejected_out_of_order')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-18', 'RN-02', 'RF-KI-04');
