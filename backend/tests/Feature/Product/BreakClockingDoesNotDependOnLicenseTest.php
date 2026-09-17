<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;

/*
 * **El fichaje de pausa nunca se degrada por la licencia** (RF-AT-12, regla dura
 * 15, ADR-019, ADR-023; decision 11 de la ficha 3.5).
 *
 * ## Por que esto ni siquiera se discute
 *
 * Porque **es fichaje**. Una pausa son dos marcas del registro horario con valor
 * legal (RL-01), y una instalacion con la licencia vencida es una instalacion con
 * un problema administrativo del hotel, no de la persona que entra a trabajar. Si
 * el boton «Pausa» dejara de funcionar al caducar la licencia, el turno de esa
 * persona se registraria como una salida y una entrada —o peor, como una jornada
 * de ocho horas sin descanso— y el hotel acabaria con un registro falso por un
 * impago.
 *
 * ADR-023 pide la defensa concreta: **ni un caso en `FeatureGate`, ni una
 * consulta a la licencia en este camino**. Esta suite es lo que lo demuestra, con
 * el precedente de `ComplianceSummaryDoesNotDependOnLicenseTest` y de
 * `KioskHealthDoesNotDependOnLicenseTest`.
 *
 * Y cubre las dos mitades: el **escaneo** con `intent: break_start` y el
 * **latido**, que es por donde la tablet se entera de que el hotel ficha las
 * pausas. Sin la segunda, la funcionalidad seguiria viva en el servidor y
 * apagada en la pantalla, que para quien ficha es lo mismo que no existir.
 */

uses(RefreshDatabase::class);

const TARJETA_SIN_LICENCIA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

beforeEach(function (): void {
    config()->set('identity.two_factor.required_roles', []);

    FrozenTime::at('2026-03-14 06:00:00');
});

/**
 * Quiosco emparejado, empleado con tramo abierto y la pausa activada en el
 * panel — todo por la via real.
 *
 * @return array{token: string, employee: string}
 */
function escenarioDePausaSinLicencia(): array
{
    $escenario = AttendanceFixtures::scenario();

    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_SIN_LICENCIA, $escenario['employee']),
    );

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => [SettingKey::ATTENDANCE_BREAK_CLOCKING->value => 'enabled']])
        ->assertStatus(200);

    app()->forgetScopedInstances();

    return $escenario;
}

/**
 * @param  array{token: string, ...}  $escenario
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Response>
 */
function ficharSinLicencia(array $escenario, string $occurredAt, array $extra = []): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => TARJETA_SIN_LICENCIA,
            ...$extra,
        ]);
}

/** Deja la instalacion con una licencia que caduco hace dos meses. */
function licenciaCaducadaParaLaPausa(): void
{
    $keys = LicenseKeys::install();

    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-02-01T00:00:00Z',
    ])));

    app()->forgetInstance(FeatureGate::class);
}

it('ficha la pausa sin ninguna licencia activada', function (): void {
    $escenario = escenarioDePausaSinLicencia();

    ficharSinLicencia($escenario, '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:00:00');

    $pausa = ficharSinLicencia($escenario, '2026-03-14T10:00:00Z', ['intent' => 'break_start']);

    $pausa->assertOk();

    expect($pausa->json('action'))->toBe('break_start')
        ->and($pausa->json('worked_minutes'))->toBe(240);
})->group('RF-AT-12', 'RF-PD-05');

it('sigue fichando la pausa con la licencia caducada', function (): void {
    $escenario = escenarioDePausaSinLicencia();
    licenciaCaducadaParaLaPausa();

    ficharSinLicencia($escenario, '2026-03-14T06:00:00Z')->assertOk();

    FrozenTime::at('2026-03-14 10:00:00');
    ficharSinLicencia($escenario, '2026-03-14T10:00:00Z', ['intent' => 'break_start'])
        ->assertOk()
        ->assertJsonPath('action', 'break_start');

    // Y la vuelta, que es la mitad que de verdad reconstruye la jornada.
    FrozenTime::at('2026-03-14 10:30:00');
    ficharSinLicencia($escenario, '2026-03-14T10:30:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'break_end');

    // El latido tambien: sin el, la tablet no enseñaria el boton y la
    // funcionalidad estaria viva en el servidor y muerta en la pantalla.
    Api::as($escenario['token'])
        ->post('/api/v1/kiosk/heartbeat', ['app_version' => '2.2.0', 'pending_queue_size' => 0])
        ->assertOk()
        ->assertJsonPath('break_clocking_enabled', true)
        ->assertJsonPath('clock_skew_tolerance_seconds', 900);
})->group('RF-AT-12', 'RF-PD-05');

it('no consulta la licencia en el camino de la pausa ni en el del latido', function (): void {
    // LA DEFENSA LITERAL DE ADR-023, y la que de verdad impide la regresion: no
    // basta con que hoy responda, hace falta que **nadie pregunte**. Un
    // `FeatureGate` que lanza al ser consultado deja en rojo cualquier
    // `if (license...)` que alguien introduzca en este camino mas adelante.
    $escenario = escenarioDePausaSinLicencia();

    ficharSinLicencia($escenario, '2026-03-14T06:00:00Z')->assertOk();

    app()->instance(FeatureGate::class, new class implements FeatureGate
    {
        private const string REASON = 'El fichaje de pausa consulto la licencia: es registro horario '
            .'con valor legal y no se degrada jamas (ADR-023, regla dura 15).';

        public function isEnabled(Feature $feature): bool
        {
            throw new RuntimeException(self::REASON);
        }

        public function statusOf(Feature $feature): FeatureAvailability
        {
            throw new RuntimeException(self::REASON);
        }
    });

    FrozenTime::at('2026-03-14 10:00:00');
    ficharSinLicencia($escenario, '2026-03-14T10:00:00Z', ['intent' => 'break_start'])
        ->assertOk()
        ->assertJsonPath('action', 'break_start');

    Api::as($escenario['token'])
        ->post('/api/v1/kiosk/heartbeat', ['app_version' => '2.2.0', 'pending_queue_size' => 0])
        ->assertOk();
})->group('RF-AT-12', 'RF-PD-05');
