<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\EloquentManagementAccountMorphType;
use App\Modules\Identity\Infrastructure\Persistence\User;

/*
 * El `model_type` de las cuentas de gestion lo publica `Identity` y sale del
 * modelo real (RF-PD-14): es lo que escriben la libreria de permisos y Sanctum.
 * Sin `morphMap` (ADR-025, nota del 26-10-2026), es el nombre de la clase; si
 * algun dia hubiera alias, esta prueba sigue atando el valor al modelo.
 */

it('publica el mismo tipo polimorfico que escribe el modelo de las cuentas', function (): void {
    expect((new EloquentManagementAccountMorphType)->morphType())
        ->toBe((new User)->getMorphClass())
        ->toBe(User::class);
})->group('RF-PD-14');
