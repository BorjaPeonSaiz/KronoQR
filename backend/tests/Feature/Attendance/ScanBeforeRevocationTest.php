<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\InterleavingEmployeeDirectory;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **RN-20: la tarjeta autentica usada antes de su retirada** (ADR-047), de punta
 * a punta y con el resolutor HMAC real.
 *
 * El fichaje de salida del ultimo dia, hecho sin red, llega despues de que RRHH
 * registrara la baja. Se rechaza igual —el mismo cuerpo, las mismas consultas
 * que cualquier rechazo (RS-03, regla dura 17)—, pero la fila de `scan_events`
 * queda atribuida a su titular y marcada para revision, que es lo que la
 * revision diaria convierte en la incidencia `scan_before_revocation`.
 *
 * `Credentials::issueFor(..., revokedReason: …)` deja la credencial revocada el
 * 2026-08-15 a las 06:00 UTC: los dos escaneos se colocan a un lado y a otro.
 */

uses(RefreshDatabase::class);

const SCAN_BEFORE_REVOCATION_NOW = '2026-08-15 10:00:00';

const SCAN_BEFORE_REVOCATION_BEFORE = '2026-08-15T05:59:59Z';

const SCAN_BEFORE_REVOCATION_AFTER = '2026-08-15T06:00:01Z';

beforeEach(function (): void {
    FrozenTime::at(SCAN_BEFORE_REVOCATION_NOW);
    Spectator::using('openapi.yaml');
});

/**
 * @return TestResponse<Response>
 */
function escanearTarjetaRetirada(string $token, string $payload, string $occurredAt, string $scanId): TestResponse
{
    return Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => $payload,
        ]);
}

/**
 * La respuesta con su `scan_id` sustituido: lo unico que puede variar entre dos
 * rechazos es el eco de lo que envio el cliente.
 *
 * @param  TestResponse<Response>  $respuesta
 */
function cuerpoCrudoSinScanId(TestResponse $respuesta, string $scanId): string
{
    return str_replace($scanId, '<scan_id>', (string) $respuesta->getContent());
}

/**
 * @return object{employee_id: int|null, flagged_for_review: bool, result: string}
 */
function filaDelEscaneo(string $scanId): object
{
    /** @var object{employee_id: int|null, flagged_for_review: bool, result: string} $fila */
    $fila = DB::table('scan_events')->where('scan_id', $scanId)->first(['employee_id', 'flagged_for_review', 'result']);

    return $fila;
}

/**
 * Peticion medida: su respuesta y cuantas consultas hizo de principio a fin.
 *
 * @return array{0: TestResponse<Response>, 1: int}
 */
function escaneoContado(string $token, string $payload, string $occurredAt, string $scanId): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $respuesta = escanearTarjetaRetirada($token, $payload, $occurredAt, $scanId);

    $consultas = \count(DB::getRawQueryLog());
    DB::disableQueryLog();

    return [$respuesta, $consultas];
}

it('rechaza igual antes y despues de la retirada, con el mismo cuerpo y las mismas consultas, y solo cambia la fila', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $retirada = Credentials::issueFor($titularId, revokedReason: 'lost')->toString();
    $falsa = Credentials::signedWithUnknownKey()->toString();

    // Calentamiento: la primera peticion paga cachés de arranque que no son del
    // camino de rechazo.
    escanearTarjetaRetirada($escenario['token'], $falsa, SCAN_BEFORE_REVOCATION_AFTER, Str::uuid7()->toString());

    $antes = Str::uuid7()->toString();
    $despues = Str::uuid7()->toString();
    $firma = Str::uuid7()->toString();

    [$respuestaAntes, $consultasAntes] = escaneoContado($escenario['token'], $retirada, SCAN_BEFORE_REVOCATION_BEFORE, $antes);
    [$respuestaDespues, $consultasDespues] = escaneoContado($escenario['token'], $retirada, SCAN_BEFORE_REVOCATION_AFTER, $despues);
    [$respuestaFirma, $consultasFirma] = escaneoContado($escenario['token'], $falsa, SCAN_BEFORE_REVOCATION_BEFORE, $firma);

    $respuestaAntes->assertStatus(422)->assertValidResponse();
    $respuestaDespues->assertStatus(422)->assertValidResponse();
    $respuestaFirma->assertStatus(422);

    // Byte a byte, salvo el eco del `scan_id` (RS-03, regla dura 17).
    expect(cuerpoCrudoSinScanId($respuestaAntes, $antes))
        ->toBe(cuerpoCrudoSinScanId($respuestaDespues, $despues))
        ->toBe(cuerpoCrudoSinScanId($respuestaFirma, $firma))
        // Y el mismo trabajo: con titular o sin el, con firma buena o mala.
        ->and([$consultasAntes, $consultasDespues])->toBe([$consultasFirma, $consultasFirma]);

    $filaAntes = filaDelEscaneo($antes);
    $filaDespues = filaDelEscaneo($despues);
    $filaFirma = filaDelEscaneo($firma);

    expect($filaAntes->result)->toBe('rejected_revoked')
        ->and($filaAntes->employee_id)->toBe($titularId)
        ->and($filaAntes->flagged_for_review)->toBeTrue()
        // Despues de la retirada: atribuida, pero no hay nada que revisar.
        ->and($filaDespues->result)->toBe('rejected_revoked')
        ->and($filaDespues->employee_id)->toBe($titularId)
        ->and($filaDespues->flagged_for_review)->toBeFalse()
        // Y una tarjeta que no es autentica no atribuye nada a nadie.
        ->and($filaFirma->result)->toBe('rejected_signature')
        ->and($filaFirma->employee_id)->toBeNull()
        ->and($filaFirma->flagged_for_review)->toBeFalse()
        // Ningun rechazo crea ni rectifica un tramo.
        ->and(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-20', 'RN-14', 'RS-03');

it('compara con la recepcion cuando la tarjeta sigue vigente y la persona esta de baja', function (): void {
    // La carrera con la baja, o un dato anterior a que la baja revocara la
    // tarjeta: no hay `revoked_at`, y la retirada es la recepcion del escaneo.
    $escenario = AttendanceFixtures::scenario();
    $baja = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated');
    $bajaId = AttendanceFixtures::employeeIdOf($baja);
    $tarjeta = Credentials::issueFor($bajaId)->toString();

    $encolado = Str::uuid7()->toString();
    $adelantado = Str::uuid7()->toString();

    // Encolado sin red a las 08:00, recibido a las 10:00: anterior.
    escanearTarjetaRetirada($escenario['token'], $tarjeta, '2026-08-15T08:00:00Z', $encolado)->assertStatus(422);
    // Reloj de la tablet adelantado: posterior a la recepcion. No se afirma nada.
    escanearTarjetaRetirada($escenario['token'], $tarjeta, '2026-08-15T10:05:00Z', $adelantado)->assertStatus(422);

    expect(filaDelEscaneo($encolado)->employee_id)->toBe($bajaId)
        ->and(filaDelEscaneo($encolado)->flagged_for_review)->toBeTrue()
        ->and(filaDelEscaneo($encolado)->result)->toBe('rejected_revoked')
        ->and(filaDelEscaneo($adelantado)->employee_id)->toBe($bajaId)
        ->and(filaDelEscaneo($adelantado)->flagged_for_review)->toBeFalse();
})->group('RN-20', 'RN-14');

it('no atribuye ni marca nada a una persona suspendida', function (): void {
    // RN-20: una suspension no tiene instante con el que comparar.
    $escenario = AttendanceFixtures::scenario();
    $suspendida = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'suspended');
    $suspendidaId = AttendanceFixtures::employeeIdOf($suspendida);

    $vigente = Credentials::issueFor($suspendidaId)->toString();
    $revocada = Credentials::issueFor($suspendidaId, Credentials::previousKey(), 'lost')->toString();

    $conVigente = Str::uuid7()->toString();
    $conRevocada = Str::uuid7()->toString();

    escanearTarjetaRetirada($escenario['token'], $vigente, SCAN_BEFORE_REVOCATION_BEFORE, $conVigente)->assertStatus(422);
    escanearTarjetaRetirada($escenario['token'], $revocada, SCAN_BEFORE_REVOCATION_BEFORE, $conRevocada)->assertStatus(422);

    expect(filaDelEscaneo($conVigente)->employee_id)->toBeNull()
        ->and(filaDelEscaneo($conVigente)->flagged_for_review)->toBeFalse()
        ->and(filaDelEscaneo($conRevocada)->employee_id)->toBeNull()
        ->and(filaDelEscaneo($conRevocada)->flagged_for_review)->toBeFalse();
})->group('RN-20');

it('devuelve el mismo rechazo al reenviar el escaneo atribuido', function (): void {
    // Regla dura 8: el reenvio de la cola offline responde lo mismo, y la fila
    // atribuida no cambia la forma del reenvio.
    $escenario = AttendanceFixtures::scenario();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $retirada = Credentials::issueFor($titularId, revokedReason: 'lost')->toString();
    $scanId = Str::uuid7()->toString();

    $primera = escanearTarjetaRetirada($escenario['token'], $retirada, SCAN_BEFORE_REVOCATION_BEFORE, $scanId);
    $reenvio = escanearTarjetaRetirada($escenario['token'], $retirada, SCAN_BEFORE_REVOCATION_BEFORE, $scanId);

    $reenvio->assertStatus(422)->assertValidResponse();

    expect($reenvio->getContent())->toBe($primera->getContent())
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1);
})->group('RN-20', 'RF-AT-07', 'RS-03');

it('reenvia un rechazo atribuido con las mismas consultas y el mismo cuerpo que uno por firma', function (): void {
    // F1 del dictamen del bloque 18: la reconstruccion de un rechazo no busca a
    // nadie. Si buscara al titular de la fila atribuida, el reenvio de una
    // tarjeta autentica retirada tardaria mas que el de una firma falsa, despues
    // del suelo y sin relleno.
    $escenario = AttendanceFixtures::scenario();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $retirada = Credentials::issueFor($titularId, revokedReason: 'lost')->toString();
    $falsa = Credentials::signedWithUnknownKey()->toString();

    $atribuido = Str::uuid7()->toString();
    $firma = Str::uuid7()->toString();

    escanearTarjetaRetirada($escenario['token'], $retirada, SCAN_BEFORE_REVOCATION_BEFORE, $atribuido)->assertStatus(422);
    escanearTarjetaRetirada($escenario['token'], $falsa, SCAN_BEFORE_REVOCATION_BEFORE, $firma)->assertStatus(422);

    expect(filaDelEscaneo($atribuido)->employee_id)->toBe($titularId);

    [$reenvioAtribuido, $consultasAtribuido] = escaneoContado($escenario['token'], $retirada, SCAN_BEFORE_REVOCATION_BEFORE, $atribuido);
    [$reenvioFirma, $consultasFirma] = escaneoContado($escenario['token'], $falsa, SCAN_BEFORE_REVOCATION_BEFORE, $firma);

    $reenvioAtribuido->assertStatus(422)->assertValidResponse();

    expect($consultasAtribuido)->toBe($consultasFirma)
        ->and(cuerpoCrudoSinScanId($reenvioAtribuido, $atribuido))->toBe(cuerpoCrudoSinScanId($reenvioFirma, $firma));
})->group('RN-20', 'RS-03', 'RF-AT-07');

it('no marca un escaneo de tarjeta retirada anterior a su emision', function (): void {
    // F2 del dictamen del bloque 18: el `occurred_at` lo pone la tablet. La
    // tarjeta se emitio el 2026-08-14 a las 06:00 UTC; un fichaje «del dia
    // anterior» con ella no pudo ocurrir y no pide revision.
    $escenario = AttendanceFixtures::scenario();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $retirada = Credentials::issueFor($titularId, revokedReason: 'lost')->toString();
    $scanId = Str::uuid7()->toString();

    escanearTarjetaRetirada($escenario['token'], $retirada, '2026-08-14T05:59:59Z', $scanId)->assertStatus(422);

    expect(filaDelEscaneo($scanId)->employee_id)->toBe($titularId)
        ->and(filaDelEscaneo($scanId)->flagged_for_review)->toBeFalse();
})->group('RN-20', 'RS-03');

// --- N3: la carrera con la baja (`offboardedBeforeScanning`) ---------------

/**
 * La tarjeta resuelve con la persona activa y, justo despues de esa lectura,
 * otra sesion registra la baja: el caso de uso la carga ya de baja.
 */
function bajaEntreResolucionYCarga(string $employeeUuid): void
{
    app()->instance(EmployeeDirectory::class, new InterleavingEmployeeDirectory(
        app(EloquentEmployeeDirectory::class),
        $employeeUuid,
        static fn () => WorkforceFixtures::terminate($employeeUuid),
    ));
}

it('marca y atribuye el escaneo de la carrera con la baja posterior al alta, y no el anterior', function (string $occurredAt, bool $marcado): void {
    $escenario = AttendanceFixtures::scenario();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $tarjeta = Credentials::issueFor($titularId)->toString();
    $scanId = Str::uuid7()->toString();

    bajaEntreResolucionYCarga($escenario['employee']);

    escanearTarjetaRetirada($escenario['token'], $tarjeta, $occurredAt, $scanId)->assertStatus(422);

    // El alta es el 2026-01-01 en Madrid: las 23:00 UTC del 31 de diciembre.
    expect(filaDelEscaneo($scanId)->result)->toBe('rejected_unknown')
        ->and(filaDelEscaneo($scanId)->employee_id)->toBe($titularId)
        ->and(filaDelEscaneo($scanId)->flagged_for_review)->toBe($marcado);
})->with([
    'posterior al alta' => ['2026-08-15T08:00:00Z', true],
    'anterior al alta' => ['2025-12-31T22:59:59Z', false],
])->group('RN-20', 'RN-14');

it('no marca la carrera con la baja de un fichaje por PIN', function (): void {
    // El PIN de una persona de baja queda fuera de RN-20 (ADR-043).
    $escenario = AttendanceFixtures::scenario();
    WorkforceFixtures::terminate($escenario['employee']);
    $scanId = Str::uuid7()->toString();

    app(RegisterScanHandler::class)->handle(
        new RegisterScanCommand(
            scanId: $scanId,
            qrPayload: null,
            occurredAt: new DateTimeImmutable('2026-08-15 08:00:00', new DateTimeZone('UTC')),
            deviceId: $escenario['device'],
            deviceUuid: $escenario['deviceUuid'],
            origin: ScanOrigin::PIN_KIOSK,
            intent: ScanIntent::AUTO,
        ),
        CredentialResolution::resolved($escenario['employee']),
    );

    expect(filaDelEscaneo($scanId)->result)->toBe('rejected_unknown')
        ->and(filaDelEscaneo($scanId)->flagged_for_review)->toBeFalse();
})->group('RN-20', 'RN-14');
