<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Con la licencia caducada, la vista de cumplimiento funciona igual**
 * (RF-PA-06, regla dura 15, ADR-019, ADR-023; decision 9 de la ficha 3.4).
 *
 * ## Por que esto no es funcionalidad accesoria
 *
 * Es una lectura del **registro legal** contra los **umbrales legales**: la unica
 * diferencia con `GET /employees/{uuid}/workdays` es que trae la regla aplicada
 * encima. Degradarla al caducar la licencia le quitaria al cliente justo la
 * pantalla con la que responde a una inspeccion sobre descansos — y lo haria en
 * el peor momento posible, porque una instalacion con la licencia vencida es una
 * instalacion con un problema administrativo abierto.
 *
 * El contraste esta en el informe por periodo, que **si** es accesorio y responde
 * `402`: aquel es una herramienta de nomina, y quien la necesita puede leer las
 * jornadas una a una mientras renueva.
 *
 * ADR-023 pide la defensa concreta: **ni un caso en `FeatureGate`, ni una
 * consulta a la licencia en este camino**. Esta suite es lo que lo demuestra, con
 * el precedente de `KioskHealthDoesNotDependOnLicenseTest`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('identity.two_factor.required_roles', []);

    FrozenTime::at('2026-03-31 09:12:03');

    LicenseKeys::install();
});

/**
 * Un hotel con una persona que encadena dos jornadas con nueve horas de descanso.
 *
 * @return array{site: int, employee: string}
 */
function escenarioDeCumplimientoSinLicencia(): array
{
    $site = WorkforceFixtures::site('Hotel sin licencia', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');

    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');

    return ['site' => $site, 'employee' => $employee];
}

/**
 * Deja la instalacion con una licencia que caduco hace dos meses.
 *
 * El reloj ya esta detenido por el `beforeEach`: la caducidad del token de
 * Sanctum se compara con el reloj real, y sin `FrozenTime` la prueba fallaria con
 * un `401` que no tiene nada que ver con lo que se comprueba.
 */
function licenciaCaducadaParaCumplimiento(): void
{
    $keys = LicenseKeys::install();

    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-02-01T00:00:00Z',
    ])));

    app()->forgetInstance(FeatureGate::class);
}

it('sirve la vista completa sin ninguna licencia activada', function (): void {
    $escenario = escenarioDeCumplimientoSinLicencia();

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    $respuesta->assertOk();

    // La respuesta completa, no una version recortada: los hallazgos, el perfil y
    // las cuatro reglas con su umbral.
    expect($respuesta->json('data'))->toHaveCount(2)
        ->and($respuesta->json('data.0.employee.uuid'))->toBe($escenario['employee'])
        ->and($respuesta->json('meta.profile.name'))->toBe('ES-hosteleria')
        ->and($respuesta->json('meta.rules'))->toHaveCount(4)
        ->and($respuesta->json('meta.criteria'))->toHaveCount(5);
})->group('RF-PA-06', 'RF-PD-05');

it('sigue sirviendo la vista con la licencia caducada', function (): void {
    escenarioDeCumplimientoSinLicencia();
    licenciaCaducadaParaCumplimiento();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertOk()
        ->assertJsonPath('meta.totals.by_rule.insufficient_rest', 1)
        ->assertJsonPath('meta.totals.by_rule.daily_excess', 1);
})->group('RF-PA-06', 'RF-PD-05');

it('no consulta la licencia en el camino de la vista de cumplimiento', function (): void {
    // LA DEFENSA LITERAL DE ADR-023, y la que de verdad impide la regresion: no
    // basta con que hoy responda, hace falta que **nadie pregunte**. Un
    // `FeatureGate` que lanza al ser consultado deja en rojo cualquier
    // `if (license...)` que alguien introduzca en este camino mas adelante.
    escenarioDeCumplimientoSinLicencia();

    app()->instance(FeatureGate::class, new class implements FeatureGate
    {
        private const string REASON = 'La vista de cumplimiento consulto la licencia: '
            .'es una lectura del registro legal contra los umbrales legales (ADR-023).';

        public function isEnabled(Feature $feature): bool
        {
            throw new RuntimeException(self::REASON);
        }

        public function statusOf(Feature $feature): FeatureAvailability
        {
            throw new RuntimeException(self::REASON);
        }
    });

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertOk();
})->group('RF-PA-06', 'RF-PD-05');
