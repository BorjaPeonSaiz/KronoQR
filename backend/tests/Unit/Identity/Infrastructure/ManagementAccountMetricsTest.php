<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Infrastructure\Metrics\RedisManagementAccountMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;

/*
 * `kronoqr_management_account_changes_total{action,role}` (RF-ID-10): las
 * combinaciones que el catalogo publica a cero son EXACTAMENTE las acciones del
 * puerto por los cuatro roles de gestion. El catalogo las escribe a mano porque
 * `Shared` no alcanza `Identity`; esta prueba ata las dos copias. Sin la serie a
 * cero, la alerta de seguridad con `increase()` no ve el primer suceso.
 */

it('publica a cero cada accion por cada rol de gestion, y nada mas', function (): void {
    $definition = array_values(array_filter(
        MetricCatalogue::all(),
        static fn (MetricDefinition $definition): bool => $definition->name === 'kronoqr_management_account_changes_total',
    ))[0] ?? null;

    $expected = [];

    foreach (ManagementAccountChange::cases() as $action) {
        foreach (UserRole::managementRoles() as $role) {
            $expected[] = 'action='.$action->value.',role='.$role->value;
        }
    }

    sort($expected);
    $declared = $definition === null ? [] : $definition->zeroSeries;
    sort($declared);

    expect($definition?->labels)->toBe(['action', 'role'])
        ->and($declared)->toBe($expected)
        ->and(RedisManagementAccountMetrics::CHANGES_TOTAL)->toBe(MetricCatalogue::KEY_PREFIX.'kronoqr_management_account_changes_total');
})->group('RF-ID-10');
