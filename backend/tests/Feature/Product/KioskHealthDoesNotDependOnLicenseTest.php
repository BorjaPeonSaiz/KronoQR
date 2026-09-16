<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Con la licencia caducada, la salud de los quioscos funciona igual**
 * (RF-PA-07, RF-KI-08, regla dura 15, ADR-019, ADR-023; decision 13 de la
 * ficha 3.3).
 *
 * ## Por que esto no es funcionalidad accesoria
 *
 * Un quiosco parado **abre incidencias** (RN-15): la gente sigue fichando en su
 * cola local y esos fichajes no los ve nadie hasta que la tablet vuelve a
 * hablar. La pantalla desde la que se descubre eso, y la pantalla de diagnostico
 * con la que se arregla, son operacion del registro legal. Degradarlas al
 * caducar la licencia le quitaria al cliente justo la herramienta con la que
 * arreglaria el problema —incluido el de renovar—.
 *
 * ADR-023 pide la defensa concreta: **ni un caso en `FeatureGate`, ni una
 * consulta a la licencia en este camino**. Esta suite es lo que lo demuestra, y
 * sigue el precedente de `DiagnosticsDoNotDependOnLicenseTest`.
 *
 * ## Que se prueba con la licencia CADUCADA y no solo ausente
 *
 * Los dos estados en los que un cliente se encuentra de verdad: una instalacion
 * recien montada que aun no ha activado nada, y una renovacion tardia. En los
 * dos, el latido y la lista responden exactamente lo mismo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site('Hotel sin licencia', 'Europe/Madrid');
    LicenseKeys::install();
});

/**
 * Deja la instalacion con una licencia que caduco hace un mes.
 *
 * El reloj se detiene con `FrozenTime` —lo unico que para a la vez el puerto
 * `Clock` y el Carbon del framework— porque la caducidad del token de Sanctum se
 * compara con el reloj real: una prueba que instalara el reloj a mano emitiria
 * un token que Sanctum considera vencido y fallaria con un `401` que no tiene
 * nada que ver con lo que se esta comprobando.
 */
function saludConLicenciaCaducada(): void
{
    $keys = LicenseKeys::install();

    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-08-01T00:00:00Z',
    ])));

    FrozenTime::at('2026-09-16 10:00:00');
    app()->forgetInstance(FeatureGate::class);
}

it('lista los quioscos con su veredicto sin ninguna licencia activada', function (): void {
    $escenario = AttendanceFixtures::scenario();

    Api::as($escenario['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'battery_level' => 64,
        'battery_charging' => false,
    ])->assertOk();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/devices')
        ->assertOk()
        // El veredicto completo, no una version recortada: `health` y `meta` son
        // obligatorios en el contrato y no pueden degradarse.
        ->assertJsonPath('devices.0.health.verdict', 'ok')
        ->assertJsonPath('devices.0.battery_level', 64)
        ->assertJsonPath('meta.thresholds.battery_low_percent', 15);
})->group('RF-PA-07', 'RF-PD-05');

it('sigue latiendo, guardando la bateria y entregando la huella con la licencia caducada', function (): void {
    saludConLicenciaCaducada();

    $escenario = AttendanceFixtures::scenario();
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // El codigo de servicio se configura y se entrega igual: la pantalla de
    // diagnostico de la tablet no es una funcion accesoria.
    Api::as($admin)
        ->patch('/api/v1/settings', ['settings' => ['KIOSK_SERVICE_CODE' => '48392017']])
        ->assertStatus(200);

    Api::as($escenario['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 3,
        'oldest_pending_at' => '2026-09-16T09:00:00Z',
        'battery_level' => 11,
        'battery_charging' => false,
    ])
        ->assertOk()
        ->assertJsonPath('service_code_hash', hash('sha256', $escenario['deviceUuid'].':48392017'));

    Api::as($admin)->get('/api/v1/devices')
        ->assertOk()
        // Y el aviso de bateria baja se sigue calculando: es lo que evita que la
        // tablet muera en mitad de un turno, con licencia o sin ella.
        ->assertJsonPath('devices.0.health.reason', 'battery_low')
        ->assertJsonPath('devices.0.health.verdict', 'warning')
        ->assertJsonPath('devices.0.oldest_pending_at', '2026-09-16T09:00:00.000Z');
})->group('RF-PA-07', 'RF-KI-08', 'RF-PD-05');

it('desvincula un quiosco y devuelve su veredicto con la licencia caducada', function (): void {
    // Sustituir una tablet averiada tampoco puede depender del estado comercial:
    // sin poder desvincular, el nombre del puesto queda bloqueado y la tablet
    // nueva no se puede dar de alta (ADR-028).
    saludConLicenciaCaducada();

    $escenario = AttendanceFixtures::scenario();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/devices/'.$escenario['deviceUuid'].'/unpair')
        ->assertOk()
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('health.verdict', 'revoked');
})->group('RF-PA-07', 'RF-PD-05');

it('no consulta la licencia en el camino de la salud de los quioscos', function (): void {
    // LA DEFENSA LITERAL DE ADR-023, y la que de verdad impide la regresion: no
    // basta con que hoy responda: hace falta que **nadie pregunte**. Un
    // `FeatureGate` que lanzara al ser consultado deja en rojo cualquier `if
    // (license...)` que alguien introduzca en este camino mas adelante.
    app()->instance(FeatureGate::class, new class implements FeatureGate
    {
        public function isEnabled(Feature $feature): bool
        {
            throw new RuntimeException(self::REASON);
        }

        public function statusOf(Feature $feature): FeatureAvailability
        {
            throw new RuntimeException(self::REASON);
        }

        private const string REASON = 'La salud de los quioscos consulto la licencia: '
            .'es operacion del registro legal (ADR-023).';
    });

    $escenario = AttendanceFixtures::scenario();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($escenario['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'battery_level' => 50,
        'battery_charging' => true,
    ])->assertOk();

    Api::as($token)->get('/api/v1/devices')->assertOk();
})->group('RF-PA-07', 'RF-PD-05');
