<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LAS DOS PRUEBAS NEGATIVAS QUE EXIGE [ADR-028](../../../../docs/adr/ADR-028-limites-del-plan-no-bloquean.md).**
 *
 * Con `max_employees` superado, `POST /api/v1/employees` responde 2xx y la
 * persona **puede fichar**. Con `max_devices` superado, la emision del token de
 * un quiosco lo vincula y **el quiosco queda operativo**.
 *
 * ADR-028 dice por que existen: *«el fallo que este ADR previene es de omision y
 * volveria sin ellas»*. Bloquear el alta deja a alguien trabajando sin registro
 * horario —infraccion del art. 34.9 ET imputable al cliente y causada por el
 * producto— y bloquear el emparejamiento deja un centro sin punto de fichaje el
 * dia que se avería un quiosco. Es ADR-019 alcanzado por un rodeo: no bloquea el
 * fichaje de hoy, bloquea el de mañana.
 *
 * ## Sobre `/kiosk/pair/confirm`
 *
 * Ese endpoint **no existe todavia**: llega con la tarea 5.6. La prueba se hace
 * hoy sobre la via real de emision de tokens de dispositivo, que es la que usa
 * la consola y la que `/pair/confirm` invocara por dentro. **La 5.6 debe
 * extender este fichero** con el emparejamiento por codigo.
 *
 * ## `max_sites` no se prueba porque no existe
 *
 * ADR-040 punto 5 lo retiro: una licencia es un centro. `PlanLimit` tiene dos
 * casos y `LicensePayloadTest` fija que sean exactamente esos dos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 07:00:00'));
});

function conLimites(int $employees, int $devices): void
{
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(
        LicenseKeys::current()->issue(['max_employees' => $employees, 'max_devices' => $devices]),
    ));
}

it('CON max_employees SUPERADO da de alta igual, y la persona puede fichar', function (): void {
    $escenario = AttendanceFixtures::scenario();
    conLimites(employees: 1, devices: 5);

    // Ya hay una persona (la del escenario), asi que el plan esta en su tope.
    expect(DB::table('employees')->where('status', 'active')->count())->toBe(1);

    $alta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees', [
            'first_name' => 'Camarero',
            'last_name' => 'De Temporada',
            'hired_at' => '2026-06-15',
            'national_id' => '00000009X',
        ]);

    // 2xx. No 402, no 403, no 409, no 422: ninguna ruta del producto puede
    // devolver un error de licencia al dar de alta a una persona.
    $alta->assertSuccessful();

    /** @var string $nuevo */
    $nuevo = $alta->json('employee.uuid');

    expect(DB::table('employees')->where('status', 'active')->count())->toBe(2);

    // Y ficha. Esta es la mitad que importa: el alta sin fichaje no serviria de
    // nada, porque la infraccion es trabajar sin registro.
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $nuevo),
    );

    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-06-15T07:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ])
        ->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-PD-04', 'RF-PD-05', 'RL-01');

it('CON max_devices SUPERADO emite el token y el quiosco queda operativo', function (): void {
    // El escenario de ADR-028: se avería el quiosco de recepcion y se sustituye
    // por otro. Si el emparejamiento se rechazara, el centro se quedaria sin
    // punto de fichaje en el peor momento posible — el del incidente.
    $siteId = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($siteId);
    $employee = WorkforceFixtures::employee($siteId, $department);

    AttendanceFixtures::device($siteId, 'Recepcion');
    conLimites(employees: 50, devices: 1);

    $sustituto = AttendanceFixtures::device($siteId, 'Recepcion nueva');

    $token = app(IssueDeviceToken::class)->handle(
        new IssueDeviceTokenCommand($sustituto['uuid'], rotation: false, actorUserId: null),
    );

    expect($token)->not->toBeNull();

    // Y el quiosco esta operativo de verdad: ficha con ese token.
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $employee),
    );

    $scanId = Str::uuid7()->toString();

    Api::as($token->plainTextToken ?? '')
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-06-15T07:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ])
        ->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-PD-04', 'RF-PD-05', 'RL-01');

it('el alta en exceso responde 2xx tambien con la licencia caducada', function (): void {
    // Los dos ejes a la vez, que es el caso real de un cliente que ni renovo ni
    // avisa de que ha crecido. Sigue sin bloquearse nada.
    WorkforceFixtures::site();
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue([
        'max_employees' => 1,
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])));

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Primera',
        'last_name' => 'Persona',
        'hired_at' => '2026-06-15',
        'national_id' => '00000001A',
    ])->assertSuccessful();

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Segunda',
        'last_name' => 'Persona',
        'hired_at' => '2026-06-15',
        'national_id' => '00000002B',
    ])->assertSuccessful();

    expect(DB::table('employees')->where('status', 'active')->count())->toBe(2);
})->group('RF-PD-04', 'RF-PD-05', 'RL-01');

it('un fallo del contador comercial no puede tumbar el alta', function (): void {
    // El observador corre en `afterCommit` y bajo `try`. Si contar fallara —la
    // licencia ilegible, Redis caido—, el alta ya esta hecha y confirmada.
    WorkforceFixtures::site();

    DB::table('license')->insert([
        'signed_key' => 'clave-ilegible',
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

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees', [
            'first_name' => 'Con',
            'last_name' => 'Licencia Rota',
            'hired_at' => '2026-06-15',
            'national_id' => '00000003C',
        ])
        ->assertSuccessful();

    expect(DB::table('employees')->where('status', 'active')->count())->toBe(1)
        // Sin licencia verificada no hay plan contra el que comparar, asi que
        // tampoco hay asiento de exceso: no se inventa un limite.
        ->and(DB::table('audit_log')->where('action', 'license.plan_exceeded')->count())->toBe(0);
})->group('RF-PD-04', 'RF-PD-05', 'RL-01');

it('el alta se completa aunque el contador comercial LANCE al contar', function (): void {
    // La rama `catch` de `ObservePlanLimits`, ejercitada de verdad.
    //
    // La prueba de arriba —licencia ilegible— **no la ejercita**: el caso de uso
    // se da la vuelta antes de contar, porque sin licencia verificada no hay
    // plan con el que comparar. Aqui hay licencia buena y lo que revienta es el
    // conteo, que es el escenario real: la consulta agota su tiempo, o el rol de
    // la aplicacion pierde el `SELECT` sobre `devices` tras una rotacion de
    // permisos a medias.
    //
    // Si esa excepcion subiera, `POST /api/v1/employees` seria un `500` y la
    // persona que empieza hoy se quedaria sin dar de alta — el bloqueo que
    // ADR-028 prohibe, alcanzado por un fallo de infraestructura.
    WorkforceFixtures::site();
    conLimites(employees: 1, devices: 5);

    app()->bind(PlanUsageCounter::class, static fn (): PlanUsageCounter => new class implements PlanUsageCounter
    {
        public function count(PlanLimit $limit): int
        {
            throw new RuntimeException('statement timeout while counting the workforce');
        }
    });

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Primera',
        'last_name' => 'Persona',
        'hired_at' => '2026-06-15',
        'national_id' => '00000011A',
    ])->assertSuccessful();

    // La segunda es la que cruzaria el umbral, y por tanto la que de verdad
    // pasa por el contador roto.
    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Segunda',
        'last_name' => 'Persona',
        'hired_at' => '2026-06-15',
        'national_id' => '00000012B',
    ])->assertSuccessful();

    expect(DB::table('employees')->where('status', 'active')->count())->toBe(2)
        // Lo unico que se pierde es la evidencia comercial de ESTE exceso.
        ->and(DB::table('audit_log')->where('action', 'license.plan_exceeded')->count())->toBe(0);
})->group('RF-PD-04', 'RF-PD-05', 'RL-01');
