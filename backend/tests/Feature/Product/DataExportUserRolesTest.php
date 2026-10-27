<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DataExportSource;
use App\Modules\Product\Domain\Support\DataExportCatalog;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * `users.csv` de la exportacion integra lleva los roles RESUELTOS de cada
 * cuenta (RF-PD-14, RL-20). Filtra la tabla de union por el `model_type` que
 * publica `Identity` y no por un literal: con el nombre de la clase escrito a
 * mano, mover el modelo dejaba la columna vacia sin que nada fallara.
 */

uses(RefreshDatabase::class);

it('exporta los roles de cada cuenta de gestion', function (): void {
    FrozenTime::at('2026-10-26 10:00:00');
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $filas = collect(iterator_to_array(
        app(DataExportSource::class)->rows(DataExportCatalog::dataset('users') ?? throw new LogicException('Sin conjunto users.')),
        false,
    ))->keyBy('user_uuid');

    expect($filas->get($admin->uuid)['roles'] ?? null)->toBe('admin')
        ->and($filas->get($rrhh->uuid)['roles'] ?? null)->toBe('rrhh');
})->group('RF-PD-14', 'RL-20');
