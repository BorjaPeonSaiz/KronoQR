<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;

/*
 * **LA PRUEBA CENTRAL DE LA TAREA 5.3** (RF-PD-05, RL-05, RL-06, ADR-019,
 * regla dura 15).
 *
 * Con la licencia CADUCADA, se ficha, se consulta el registro, se exporta para
 * la Inspeccion, se corrige una jornada y el portal del empleado responde. Si
 * alguna de estas pruebas falla, la tarea esta mal hecha y el producto deja al
 * cliente incumpliendo el art. 34.9 ET por una accion del fabricante.
 *
 * ## Por que se prueba desde fuera y no solo en el dominio
 *
 * `tests/Unit/Product/Domain/FeatureBoundaryTest.php` prueba que no EXISTE forma de expresar la desactivacion
 * del registro legal. Esto prueba que, ademas, HOY funciona: entre las dos no
 * queda hueco. Una sola de ellas si lo dejaria — un `if` en un middleware, por
 * ejemplo, no lo veria la unitaria.
 *
 * ## La licencia esta caducada de verdad
 *
 * No se simula el `FeatureGate`: se activa una clave real, firmada con un par
 * generado al vuelo, cuya vigencia termino, y se adelanta el reloj. Es el estado
 * exacto en el que estara un cliente que no renueve.
 */

uses(RefreshDatabase::class);

const AHORA_CADUCADA = '2027-06-15 09:00:00';

/**
 * Deja la instalacion con una licencia CADUCADA y el reloj despues de su
 * vigencia.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string}
 */
function instalacionConLicenciaCaducada(): array
{
    $keys = LicenseKeys::install();
    $escenario = AttendanceFixtures::scenario();

    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
    ])));

    // Y ahora es 2027: la licencia lleva medio año caducada.
    app()->instance(Clock::class, FixedClock::at(AHORA_CADUCADA));
    app()->forgetInstance(FeatureGate::class);

    expect(app(GetLicenseStatusHandler::class)->handle()->state)->toBe(LicenseState::Expired);

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $escenario['employee'])
            ->rejecting('FH1.a3.0000000000000000000000.0000000000000000', CredentialRejectionReason::INVALID_SIGNATURE),
    );

    return $escenario;
}

it('CON LA LICENCIA CADUCADA se ficha y el tramo queda registrado', function (): void {
    // La verificacion literal de ADR-019 y de la regla dura 15. Si esto falla,
    // el producto deja al cliente sin registro horario por una fecha de
    // vencimiento comercial.
    $escenario = instalacionConLicenciaCaducada();
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2027-06-15T07:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ]);

    $respuesta->assertOk();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->value('clocked_out_at'))->toBeNull();
})->group('RF-PD-05', 'RL-01', 'RL-05', 'RL-06');

it('CON LA LICENCIA CADUCADA el quiosco sigue teniendo padron y latido', function (): void {
    // La sincronizacion de la cola offline y el padron cacheado (ADR-023: «un
    // fichaje encolado ya ocurrio; negarle la subida seria destruir un
    // registro»).
    $escenario = instalacionConLicenciaCaducada();

    Api::as($escenario['token'])->get('/api/v1/kiosk/roster')->assertOk();
    Api::as($escenario['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '1.0.0',
        'pending_queue_size' => 0,
    ])->assertSuccessful();
})->group('RF-PD-05', 'RL-01');

it('CON LA LICENCIA CADUCADA la exportacion para la Inspeccion responde', function (): void {
    // RL-06. Negarla ante un requerimiento es inconcebible, y ADR-019 lo dice
    // con esas palabras.
    instalacionConLicenciaCaducada();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)))
        ->get('/api/v1/reports/legal-export?from=2027-06-01&to=2027-06-15')
        ->assertOk();
})->group('RF-PD-05', 'RL-06');

it('CON LA LICENCIA CADUCADA se consulta el registro de una persona', function (): void {
    // RL-03: el registro debe ser accesible.
    $escenario = instalacionConLicenciaCaducada();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/employees/'.$escenario['employee'].'/workdays?from=2027-06-01&to=2027-06-15')
        ->assertOk();
})->group('RF-PD-05', 'RL-03');

it('CON LA LICENCIA CADUCADA el portal del empleado responde', function (): void {
    // RL-05, derecho del trabajador a su propio registro. Es de las cosas que
    // menos pueden depender de una relacion comercial ajena a esa persona.
    $escenario = instalacionConLicenciaCaducada();

    $session = PortalLogins::open($escenario['employee']);

    Api::as($session)->get('/api/v1/me/workdays?from=2027-06-01&to=2027-06-15')->assertOk();
    Api::as($session)->get('/api/v1/me/export?from=2027-06-01&to=2027-06-15')->assertOk();
})->group('RF-PD-05', 'RL-05');

it('CON LA LICENCIA CADUCADA la pantalla de licencia sigue abierta', function (): void {
    // Es la pantalla desde la que se arregla el problema. Cerrarla al caducar
    // dejaria al cliente sin poder activar la renovacion que acaba de comprar.
    instalacionConLicenciaCaducada();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/license')->assertOk();
    Api::as($token)->get('/api/v1/settings')->assertOk();
    Api::as($token)->get('/api/v1/compliance-profile')->assertOk();
})->group('RF-PD-05');

it('CON LA LICENCIA CADUCADA las sondas de salud responden 200', function (): void {
    // La licencia no dice si el proceso vive. Devolver 503 haria que el
    // orquestador retirara del balanceo un contenedor que ficha perfectamente.
    instalacionConLicenciaCaducada();

    $health = Api::guest()->get('/api/v1/health');

    $health->assertOk();

    expect($health->json('status'))->toBe('ok');

    Api::guest()->get('/api/v1/ready')->assertOk();
})->group('RF-PD-05');

it('CON LA LICENCIA CADUCADA se corrige una jornada con su motivo', function (): void {
    // RN-13 y RL-04: sin correcciones trazadas el registro no se puede mantener
    // veraz, y un registro que no se puede corregir es un registro que miente.
    $escenario = instalacionConLicenciaCaducada();

    $scanId = Str::uuid7()->toString();
    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2027-06-15T07:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ])->assertOk();

    /** @var string $uuid */
    $uuid = DB::table('shift_entries')->value('uuid');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->patch('/api/v1/shift-entries/'.$uuid, [
            'clocked_out_at' => '2027-06-15T15:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(2)
        ->and(DB::table('shift_entries')->where('status', 'superseded')->count())->toBe(1);
})->group('RF-PD-05', 'RL-04');

it('CON LA LICENCIA CADUCADA la auditoria se consulta y se verifica', function (): void {
    // RL-04, valor probatorio.
    instalacionConLicenciaCaducada();

    expect(app(VerifyAuditChain::class)->handle(1000)->isIntact())
        ->toBeTrue();
})->group('RF-PD-05', 'RL-04');

it('SIN NINGUNA LICENCIA se ficha igual', function (): void {
    // Es el estado de una instalacion recien puesta en marcha, antes de que
    // nadie pegue la clave. Si el fichaje dependiera de ella, el producto no se
    // podria ni instalar.
    $escenario = AttendanceFixtures::scenario();
    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:00:00'));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $escenario['employee']),
    );

    expect(DB::table('license')->count())->toBe(0);

    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ])->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-PD-05', 'RL-01');

it('CON UNA LICENCIA ILEGIBLE se ficha igual', function (): void {
    // Una fila corrupta —o editada a mano— no puede acercarse al camino de
    // fichaje. Es el mismo criterio que la tarea 5.1 aplico a la configuracion.
    $escenario = AttendanceFixtures::scenario();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:00:00'));

    DB::table('license')->insert([
        'signed_key' => 'esto-no-es-una-clave',
        'license_id' => 'x',
        'customer_name' => 'x',
        'plan' => 'x',
        'max_employees' => 1,
        'max_devices' => 1,
        'features' => '[]',
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2026-01-01T00:00:00Z',
        'activated_at' => '2026-01-01T00:00:00Z',
        'activated_by_user_id' => null,
        'last_verified_at' => null,
    ]);

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $escenario['employee']),
    );

    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ])->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(app(GetLicenseStatusHandler::class)->handle()->state)->toBe(LicenseState::Unverifiable);
})->group('RF-PD-05', 'RL-01');

it('CON LA LICENCIA CADUCADA se sincroniza un lote de la cola offline', function (): void {
    // ADR-023 lo dice con estas palabras: «un fichaje encolado ya ocurrio;
    // negarle la subida seria destruir un registro». Es el escenario mas
    // peligroso de todos: la tablet lleva horas sin red y trae media jornada
    // dentro.
    $escenario = instalacionConLicenciaCaducada();

    $entrada = Str::uuid7()->toString();
    $salida = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', [
            'scans' => [
                [
                    'scan_id' => $entrada,
                    'occurred_at' => '2027-06-15T07:02:31Z',
                    'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
                ],
                [
                    'scan_id' => $salida,
                    'occurred_at' => '2027-06-15T15:02:31Z',
                    'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
                ],
            ],
        ]);

    //  es el codigo del lote: cada escaneo trae su propio desenlace. Lo
    // que importa es que **entra**, no que el codigo sea 200.
    $respuesta->assertStatus(207);

    // El lote entra ENTERO: un tramo abierto y cerrado, no una parte.
    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->value('clocked_out_at'))->not->toBeNull()
        ->and(DB::table('scan_events')->count())->toBe(2);
})->group('RF-PD-05', 'RL-01', 'RL-12');

it('CON LA LICENCIA CADUCADA se ficha con el PIN de respaldo', function (): void {
    // RF-AT-11: el camino de quien llega sin su tarjeta. Si la licencia lo
    // cerrara, esa persona trabajaria sin registro por haber olvidado un trozo
    // de cartulina.
    $escenario = instalacionConLicenciaCaducada();

    $publicKey = EmployeePins::configureSealing();
    EmployeePins::issue($escenario['employee'], '374195');

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2027-06-15T07:02:31Z',
            'employee_code' => EmployeePins::codeOf($escenario['employee']),
            'pin_sealed' => EmployeePins::seal('374195', $publicKey),
        ]);

    $respuesta->assertOk();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->value('clock_in_source'))->toBe('pin_kiosk');
})->group('RF-PD-05', 'RL-01', 'RF-AT-11');
