<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device as DeviceModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/scan/discarded` de punta a punta (RN-22, ADR-047), con el
 * resolutor HMAC real y validado contra el contrato.
 *
 * El aviso **no registra nada**: guarda el aviso y el resultado de atribuirlo, y
 * acusa todos los `scan_id` recibidos, se atribuyan o no. Ni el payload ni el
 * codigo se guardan. La respuesta no dice a quien se atribuyo, y el tiempo
 * tampoco (F5 del dictamen de seguridad del bloque 18).
 */

uses(RefreshDatabase::class);

const DISCARDED_SCAN_REPORT_NOW = '2026-08-15 10:00:00';

beforeEach(function (): void {
    FrozenTime::at(DISCARDED_SCAN_REPORT_NOW);
    Spectator::using('openapi.yaml');
});

/**
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, metrics: RecordingScanMetrics}
 */
function escenarioDeDescartes(): array
{
    $escenario = AttendanceFixtures::scenario();
    $metrics = new RecordingScanMetrics;

    app()->instance(ScanMetrics::class, $metrics);

    return [...$escenario, 'metrics' => $metrics];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function avisoQr(string $payload, array $overrides = []): array
{
    return [
        'scan_id' => Str::uuid7()->toString(),
        'occurred_at' => '2026-08-15T06:58:00Z',
        'kind' => 'qr',
        'http_status' => 400,
        'problem_type' => 'urn:kronoqr:problem:invalid-request',
        'discarded_at' => '2026-08-15T07:10:00Z',
        'qr_payload' => $payload,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function avisoPin(string $code, array $overrides = []): array
{
    return [
        'scan_id' => Str::uuid7()->toString(),
        'occurred_at' => '2026-08-15T06:59:00Z',
        'kind' => 'pin',
        'http_status' => 400,
        'problem_type' => null,
        'discarded_at' => '2026-08-15T07:10:00Z',
        'employee_code' => $code,
        ...$overrides,
    ];
}

/**
 * @param  array<mixed>  $reports
 * @return TestResponse<Response>
 */
function avisarDescartes(string $token, array $reports): TestResponse
{
    return Api::as($token)->post('/api/v1/scan/discarded', ['reports' => $reports]);
}

function codigoDelDescarte(string $employeeUuid): string
{
    $code = DB::table('employees')->where('uuid', $employeeUuid)->value('employee_code');

    return is_string($code) ? $code : '';
}

/**
 * @return object{owner_employee_id: int|null, attribution: string, already_recorded: bool, origin: string, credential_issued_at: string|null}
 */
function filaDelAviso(mixed $scanId): object
{
    $scanId = is_string($scanId) ? $scanId : '';

    /** @var object{owner_employee_id: int|null, attribution: string, already_recorded: bool, origin: string, credential_issued_at: string|null} $fila */
    $fila = DB::table('discarded_scan_reports')->where('scan_id', $scanId)->first();

    return $fila;
}

// --- La atribucion ---------------------------------------------------------

it('acusa todos los avisos y atribuye solo la tarjeta autentica y el codigo de quien puede fichar', function (): void {
    $escenario = escenarioDeDescartes();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $tarjeta = Credentials::issueFor($titularId)->toString();
    $baja = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated');

    $porTarjeta = avisoQr($tarjeta);
    $porCodigo = avisoPin(codigoDelDescarte($escenario['employee']));
    $falsa = avisoQr(Credentials::signedWithUnknownKey()->toString());
    $inexistente = avisoPin('EZZZZZZZZ');
    $deBaja = avisoPin(codigoDelDescarte($baja));
    $basura = avisoQr('esto no es una tarjeta', ['problem_type' => null]);

    $respuesta = avisarDescartes($escenario['token'], [$porTarjeta, $porCodigo, $falsa, $inexistente, $deBaja, $basura]);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('acknowledged'))->toBe(array_column([$porTarjeta, $porCodigo, $falsa, $inexistente, $deBaja, $basura], 'scan_id'))
        ->and(filaDelAviso($porTarjeta['scan_id'])->attribution)->toBe('credential')
        ->and(filaDelAviso($porTarjeta['scan_id'])->owner_employee_id)->toBe($titularId)
        ->and(filaDelAviso($porTarjeta['scan_id'])->credential_issued_at)->toStartWith('2026-08-14 06:00:00')
        ->and(filaDelAviso($porCodigo['scan_id'])->attribution)->toBe('employee_code')
        ->and(filaDelAviso($porCodigo['scan_id'])->owner_employee_id)->toBe($titularId)
        ->and(filaDelAviso($falsa['scan_id'])->attribution)->toBe('none')
        ->and(filaDelAviso($inexistente['scan_id'])->attribution)->toBe('none')
        ->and(filaDelAviso($deBaja['scan_id'])->attribution)->toBe('none')
        ->and(filaDelAviso($basura['scan_id'])->owner_employee_id)->toBeNull()
        // El aviso no registra nada.
        ->and(DB::table('scan_events')->count())->toBe(0)
        ->and(DB::table('shift_entries')->count())->toBe(0)
        // Se cuenta al insertar, con si se atribuyo; sin persona.
        ->and($escenario['metrics']->discarded)->toHaveCount(6)
        ->and(array_values(array_filter(array_column($escenario['metrics']->discarded, 'attributed'))))->toHaveCount(2);
})->group('RN-22', 'RF-AT-07', 'RS-03');

it('atribuye la tarjeta retirada solo si el fichaje fue anterior a la retirada', function (): void {
    // RN-20 dentro de RN-22: la misma regla. La tarjeta se revoco el
    // 2026-08-15 a las 06:00 UTC.
    $escenario = escenarioDeDescartes();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $retirada = Credentials::issueFor($titularId, revokedReason: 'lost')->toString();

    $antes = avisoQr($retirada, ['occurred_at' => '2026-08-15T05:30:00Z']);
    $despues = avisoQr($retirada, ['occurred_at' => '2026-08-15T06:30:00Z']);

    avisarDescartes($escenario['token'], [$antes, $despues])->assertOk();

    expect(filaDelAviso($antes['scan_id'])->attribution)->toBe('credential')
        ->and(filaDelAviso($antes['scan_id'])->owner_employee_id)->toBe($titularId)
        ->and(filaDelAviso($despues['scan_id'])->attribution)->toBe('none');
})->group('RN-22', 'RN-20');

it('no guarda el contenido del QR ni el codigo tecleado', function (): void {
    $escenario = escenarioDeDescartes();
    $tarjeta = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']))->toString();
    $codigo = codigoDelDescarte($escenario['employee']);

    avisarDescartes($escenario['token'], [avisoQr($tarjeta), avisoPin($codigo)])->assertOk();

    $volcado = json_encode(DB::table('discarded_scan_reports')->get()->all(), JSON_THROW_ON_ERROR);

    expect($volcado)->not->toContain($tarjeta)
        ->and($volcado)->not->toContain($codigo)
        ->and(\in_array('qr_payload', DB::getSchemaBuilder()->getColumnListing('discarded_scan_reports'), true))->toBeFalse()
        ->and(\in_array('employee_code', DB::getSchemaBuilder()->getColumnListing('discarded_scan_reports'), true))->toBeFalse();
})->group('RN-22', 'RS-03');

// --- Idempotencia ------------------------------------------------------------

it('acusa igual un aviso repetido sin duplicar la fila ni la metrica', function (): void {
    $escenario = escenarioDeDescartes();
    $aviso = avisoPin(codigoDelDescarte($escenario['employee']));

    $primera = avisarDescartes($escenario['token'], [$aviso]);
    $segunda = avisarDescartes($escenario['token'], [$aviso]);

    $segunda->assertOk()->assertValidResponse();

    expect($segunda->getContent())->toBe($primera->getContent())
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $aviso['scan_id'])->count())->toBe(1)
        // F8: se cuenta al insertar, no al reenviar.
        ->and($escenario['metrics']->discarded)->toHaveCount(1);
})->group('RN-22', 'RF-AT-07');

it('acusa y marca como ya registrado el aviso de un fichaje que si quedo en el registro', function (): void {
    $escenario = escenarioDeDescartes();
    $scanId = Str::uuid7()->toString();

    DB::table('scan_events')->insert([
        'scan_id' => $scanId,
        'device_id' => $escenario['device'],
        'employee_id' => null,
        'occurred_at' => '2026-08-15 06:58:00+00',
        'recorded_at' => '2026-08-15 07:00:00+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_signature',
        'flagged_for_review' => false,
        'client_meta' => '{}',
    ]);

    avisarDescartes($escenario['token'], [avisoPin(codigoDelDescarte($escenario['employee']), ['scan_id' => $scanId])])
        ->assertOk()
        ->assertJsonPath('acknowledged.0', $scanId);

    expect(filaDelAviso($scanId)->already_recorded)->toBeTrue();
})->group('RN-22', 'RF-AT-07');

// --- Lo que no vale ----------------------------------------------------------

it('rechaza un envio que no cumple el contrato', function (array $reports): void {
    $escenario = escenarioDeDescartes();

    avisarDescartes($escenario['token'], $reports)->assertStatus(400);

    expect(DB::table('discarded_scan_reports')->count())->toBe(0);
})->with([
    'once avisos' => [array_map(static fn (int $i): array => avisoPin('E'.$i), range(1, 11))],
    'el PIN o su sobre' => [[[...avisoPin('E1'), 'pin_sealed' => 'abc']]],
    'payload con kind pin' => [[[...avisoPin('E1'), 'qr_payload' => 'FH1.a3.x.y']]],
    'codigo con kind qr' => [[[...avisoQr('FH1.a3.x.y'), 'employee_code' => 'E1']]],
    'un 500 no es un descarte' => [[avisoPin('E1', ['http_status' => 500])]],
    'un scan_id v4' => [[avisoPin('E1', ['scan_id' => '9b2f2c3e-1d4a-4c3e-9b21-4d5e6f7a8b90'])]],
    'un problema ajeno al producto' => [[avisoPin('E1', ['problem_type' => 'about:blank'])]],
    'sin avisos' => [[]],
])->group('RN-22', 'RQ-06');

// --- Autorizacion (regla dura 18) -------------------------------------------

it('no deja avisar sin token', function (): void {
    Api::guest()->post('/api/v1/scan/discarded', ['reports' => [avisoPin('E1')]])->assertStatus(401);

    expect(DB::table('discarded_scan_reports')->count())->toBe(0);
})->group('RN-22', 'RS-04', 'RQ-07');

it('deniega el aviso a cada rol de gestion', function (UserRole $rol): void {
    // Ninguna sesion de gestion lleva `scan:write` (§7.3). Un aviso abre
    // incidencias sobre personas: no lo siembra ni el administrador.
    escenarioDeDescartes();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($rol)))
        ->post('/api/v1/scan/discarded', ['reports' => [avisoPin('E1')]])
        ->assertStatus(403);

    expect(DB::table('discarded_scan_reports')->count())->toBe(0);
})->with([
    'administrador' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
    'empleado' => [UserRole::EMPLEADO],
])->group('RN-22', 'RS-04', 'RQ-07');

it('deniega el aviso a una cuenta con rol de quiosco que no es un dispositivo', function (): void {
    escenarioDeDescartes();

    Api::as(ManagementUsers::kioskToken())
        ->post('/api/v1/scan/discarded', ['reports' => [avisoPin('E1')]])
        ->assertStatus(403);

    expect(DB::table('discarded_scan_reports')->count())->toBe(0);
})->group('RN-22', 'RS-04', 'RQ-07');

it('deniega el aviso a un quiosco cuyo token no tiene scan:write', function (): void {
    $site = WorkforceFixtures::site('Hotel sin ambito');
    $device = AttendanceFixtures::device($site);
    $sinAmbito = DeviceModel::query()->findOrFail($device['id'])
        ->createToken('Solo padron', [TokenAbility::ROSTER_READ->value])
        ->plainTextToken;

    Api::as($sinAmbito)->post('/api/v1/scan/discarded', ['reports' => [avisoPin('E1')]])->assertStatus(403);

    expect(DB::table('discarded_scan_reports')->count())->toBe(0);
})->group('RN-22', 'RS-04', 'RQ-07');

it('deniega el aviso a una sesion de portal', function (): void {
    $empleado = ManagementUsers::withRole(UserRole::EMPLEADO);
    $portal = $empleado->createToken('Portal', [TokenAbility::SELF_READ->value])->plainTextToken;

    Api::as($portal)->post('/api/v1/scan/discarded', ['reports' => [avisoPin('E1')]])->assertStatus(403);
})->group('RN-22', 'RS-04', 'RQ-07');

// --- Tiempo constante (F5) ---------------------------------------------------

it('cuesta lo mismo en consultas y en tiempo se atribuya o no, en lotes de diez', function (): void {
    // F5 del dictamen del bloque 18: la respuesta no dice si se atribuyo, asi
    // que lo unico que podria hablar es el tiempo. Siete casos: tarjeta vigente,
    // retirada y falsa; codigo existente, inexistente y de baja; y un `scan_id`
    // ya registrado. Cada aviso se rellena hasta el suelo.
    //
    // **Las consultas se comparan dentro de cada via** —tarjeta y PIN—: la via
    // la declara la tablet y no dice nada de nadie, y la tarjeta paga ademas el
    // resolutor. El TIEMPO se compara entre los siete.
    $escenario = escenarioDeDescartes();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $vigente = Credentials::issueFor($titularId)->toString();
    $retirada = Credentials::issueFor($titularId, Credentials::previousKey(), 'lost')->toString();
    $falsa = Credentials::signedWithUnknownKey()->toString();
    $baja = codigoDelDescarte(WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated'));
    $existente = codigoDelDescarte($escenario['employee']);
    $suelo = Config::integer('security.rejection_floor_ms');

    // Ocho envios seguidos del mismo quiosco: por encima de la zona propia de
    // seis por minuto, que aqui no es lo que se mide.
    Config::set('kiosk.rate_limits.discarded_per_device', 100);

    $registrado = static function () use ($escenario): string {
        $scanId = Str::uuid7()->toString();
        DB::table('scan_events')->insert([
            'scan_id' => $scanId, 'device_id' => $escenario['device'], 'employee_id' => null,
            'occurred_at' => '2026-08-15 06:58:00+00', 'recorded_at' => '2026-08-15 07:00:00+00',
            'origin' => 'qr_kiosk', 'intent' => 'auto', 'result' => 'rejected_signature',
            'flagged_for_review' => false, 'client_meta' => '{}',
        ]);

        return $scanId;
    };

    $casos = [
        'tarjeta vigente' => ['qr', static fn (): array => avisoQr($vigente)],
        'tarjeta retirada' => ['qr', static fn (): array => avisoQr($retirada)],
        'tarjeta falsa' => ['qr', static fn (): array => avisoQr($falsa)],
        'ya registrado' => ['qr', static fn (): array => avisoQr($vigente, ['scan_id' => $registrado()])],
        'codigo existente' => ['pin', static fn (): array => avisoPin($existente)],
        'codigo inexistente' => ['pin', static fn (): array => avisoPin('EZZZZZZZZ')],
        'codigo de baja' => ['pin', static fn (): array => avisoPin($baja)],
    ];

    // Calentamiento: la primera peticion paga arranques que no son del aviso.
    avisarDescartes($escenario['token'], [avisoPin($existente)])->assertOk();

    $consultas = ['qr' => [], 'pin' => []];
    /** @var array<string, list<float>> $porAviso */
    $porAviso = [];

    foreach ($casos as $caso => [$via, $aviso]) {
        $lote = [];

        for ($i = 0; $i < 10; $i++) {
            $lote[] = $aviso();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $inicio = hrtime(true);

        avisarDescartes($escenario['token'], $lote)->assertOk();

        $porAviso[$caso] = [(hrtime(true) - $inicio) / 1_000_000 / 10];
        $consultas[$via][$caso] = \count(DB::getRawQueryLog());
        DB::disableQueryLog();
    }

    expect(array_unique(array_values($consultas['qr'])))->toHaveCount(1, 'Tarjeta: '.json_encode($consultas['qr']))
        ->and(array_unique(array_values($consultas['pin'])))->toHaveCount(1, 'PIN: '.json_encode($consultas['pin']));

    $medias = array_map(static fn (array $ms): float => $ms[0], $porAviso);

    foreach ($medias as $caso => $ms) {
        expect($ms)->toBeGreaterThanOrEqual($suelo * 0.9, "El caso «{$caso}» ha tardado {$ms} ms por aviso, por debajo del suelo.");
    }

    // La misma banda de 10 ms por aviso que `ConstantTimeRejectionTest`.
    expect(max($medias) - min($medias))->toBeLessThan(10.0, 'Los avisos se distinguen por tiempo: '.json_encode($medias));
})->group('RN-22', 'RS-03');

it('limita los avisos por dispositivo en su zona propia, seis por minuto', function (): void {
    // F8 del dictamen del bloque 18 y el contrato: la zona no consume la de los
    // escaneos, y un token de quiosco robado no siembra avisos mas deprisa.
    $escenario = escenarioDeDescartes();

    for ($i = 0; $i < 6; $i++) {
        avisarDescartes($escenario['token'], [avisoPin('E'.$i)])->assertOk();
    }

    avisarDescartes($escenario['token'], [avisoPin('E7')])->assertStatus(429);

    expect(DB::table('discarded_scan_reports')->count())->toBe(6);
})->group('RN-22', 'RS-02');
