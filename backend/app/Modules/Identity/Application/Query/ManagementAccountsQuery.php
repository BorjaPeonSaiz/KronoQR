<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

use App\Modules\Identity\Application\Port\ManagementAccountDirectory;
use App\Modules\Identity\Application\Port\ManagementAccountFilter;
use App\Modules\Identity\Application\Port\ManagementAccountRecord;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Las cuentas de gestion vistas por quien las administra (**RF-ID-10**;
 * `GET /api/v1/management-accounts` e `identity:list-users`).
 *
 * Lectura pura: no escribe ni audita. Listar las cuentas no es un acceso a
 * datos de la plantilla, y lo que lista —nombre, correo y rol de quien entra al
 * panel— es justo lo que la guia de endurecimiento manda revisar cada trimestre.
 *
 * El estado de la contrasena se resuelve aqui, con el reloj inyectado (regla
 * dura 2): el adaptador da la caducidad y el dominio decide que significa ahora.
 */
final readonly class ManagementAccountsQuery
{
    public function __construct(
        private ManagementAccountDirectory $directory,
        private Clock $clock,
    ) {}

    public function page(ManagementAccountFilter $filter, int $page, int $perPage): ManagementAccountListing
    {
        $result = $this->directory->page($filter, $page, $perPage);
        $now = $this->clock->now();

        return new ManagementAccountListing(
            array_map(
                static fn (ManagementAccountRecord $account): ManagementAccountView => new ManagementAccountView(
                    $account,
                    PasswordStatus::of($account->temporaryPasswordExpiresAt, $now),
                ),
                $result->items,
            ),
            $result->page,
            $result->perPage,
            $result->total,
            $result->totalPages(),
        );
    }

    public function find(string $uuid): ?ManagementAccountView
    {
        $account = $this->directory->find($uuid);

        return $account === null
            ? null
            : new ManagementAccountView($account, PasswordStatus::of($account->temporaryPasswordExpiresAt, $this->clock->now()));
    }
}
