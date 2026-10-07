<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Port\ManagementAccountFilter;
use App\Modules\Identity\Application\Query\ManagementAccountsQuery;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * `php artisan identity:list-users` — las cuentas de gestion de la instalacion
 * (RF-ID-10).
 *
 * Es la revision trimestral de la guia de endurecimiento sin `psql`: quien tiene
 * acceso, con que rol, si tiene el segundo factor activo y si su contrasena
 * sigue siendo temporal. Solo lee: no escribe nada ni deja asiento.
 *
 * **ESTA SALIDA LLEVA CORREOS.** No la redirijas a un fichero de instalacion ni
 * la pegues en una incidencia: es la lista de quien puede entrar al panel. Es el
 * unico comando `identity:*` que los imprime, y lo hace porque la revision
 * consiste precisamente en contrastarlos con la lista de personal.
 */
final class ListManagementUsersCommand extends AbstractManagementAccountCommand
{
    protected $signature = 'identity:list-users
        {--status= : active o deactivated. Sin el, las dos}
        {--role= : admin, rrhh, responsable_departamento o auditor}';

    protected $description = 'Lista las cuentas de gestion. ATENCION: la salida incluye correos; no la redirijas a ficheros.';

    /** Techo de filas: una instalacion tiene decenas de cuentas, no miles. */
    private const int MAX_ROWS = 500;

    public function handle(ManagementAccountsQuery $query): int
    {
        $status = $this->stringOption('status');
        $role = $this->stringOption('role');
        $roleFilter = $role === null ? null : UserRole::tryFrom($role);

        if (($status !== null && ! \in_array($status, ['active', 'deactivated'], true))
            || ($role !== null && ($roleFilter === null || ! $roleFilter->isManagementRole()))) {
            $this->components->error('Filtro no valido. --status: active o deactivated; --role: un rol de gestion.');

            return self::FAILURE;
        }

        $listing = $query->page(
            new ManagementAccountFilter(
                active: $status === null ? null : $status === 'active',
                role: $roleFilter,
            ),
            1,
            self::MAX_ROWS,
        );

        $this->table(
            ['UUID', 'Correo', 'Rol', 'Estado', '2FA', 'Contrasena', 'Ultimo acceso (UTC)'],
            array_map(static fn (ManagementAccountView $view): array => [
                $view->account->uuid,
                $view->account->email,
                implode(',', array_map(static fn (UserRole $r): string => $r->value, $view->account->roles)),
                $view->account->active ? 'active' : 'deactivated',
                $view->account->twoFactorEnabled ? 'si' : 'no',
                $view->passwordStatus->value,
                $view->account->lastLoginAt?->format('Y-m-d H:i') ?? '-',
            ], $listing->items),
        );

        $this->components->info($listing->total.' cuenta(s).');

        return self::SUCCESS;
    }
}
