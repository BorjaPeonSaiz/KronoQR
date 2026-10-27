<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\ManagementAccountMorphType;

/**
 * {@see ManagementAccountMorphType} sacado del propio modelo: es exactamente
 * lo que la libreria de permisos y Sanctum escriben al asignar un rol o emitir
 * un token a una cuenta de gestion.
 */
final readonly class EloquentManagementAccountMorphType implements ManagementAccountMorphType
{
    public function morphType(): string
    {
        return (new User)->getMorphClass();
    }
}
