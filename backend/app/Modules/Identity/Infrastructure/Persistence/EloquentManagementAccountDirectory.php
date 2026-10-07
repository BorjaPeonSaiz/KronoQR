<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\ManagementAccountDirectory;
use App\Modules\Identity\Application\Port\ManagementAccountFilter;
use App\Modules\Identity\Application\Port\ManagementAccountPage;
use App\Modules\Identity\Application\Port\ManagementAccountRecord;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El listado de cuentas de gestion sobre PostgreSQL (RF-ID-10).
 *
 * ## Una consulta por pagina, no una por fila
 *
 * Roles y departamentos dirigidos se agregan en la misma consulta con
 * `json_agg` en subconsultas escalares: sin eso, pintar cien cuentas serian
 * doscientas consultas. Los departamentos se leen por nombre de tabla y no con
 * un modelo de `Workforce`, que este modulo no puede importar (doc 02 §1.6): la
 * misma licencia que ya se toma `User::accessScope()`.
 *
 * ## Solo cuentas de gestion
 *
 * Las que tienen alguno de los cuatro roles de RF-ID-02. Una fila de `users`
 * con rol `kiosk` o `empleado` no es una cuenta del panel y no aparece.
 *
 * ## Busqueda
 *
 * Las mismas reglas que la `q` de `GET /employees`: `ILIKE` con `unaccent()` a
 * los dos lados, `%`, `_` y `\` escapados y el termino siempre enlazado.
 *
 * ## Orden
 *
 * Activas primero, por nombre y por `id` para desempatar: es parte del
 * contrato, y sin desempate dos cuentas con el mismo nombre podrian saltar de
 * pagina entre dos peticiones.
 */
final readonly class EloquentManagementAccountDirectory implements ManagementAccountDirectory
{
    public function page(ManagementAccountFilter $filter, int $page, int $perPage): ManagementAccountPage
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $this->filtered($filter)->count();

        $rows = $this->withColumns($this->filtered($filter))
            ->orderByDesc('u.is_active')
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->forPage($page, $perPage)
            ->get()
            ->all();

        return new ManagementAccountPage(
            array_values(array_map($this->toRecord(...), $rows)),
            $page,
            $perPage,
            $total,
        );
    }

    public function find(string $uuid): ?ManagementAccountRecord
    {
        $row = $this->withColumns($this->managementAccounts())
            ->where('u.uuid', $uuid)
            ->first();

        return $row === null ? null : $this->toRecord($row);
    }

    private function filtered(ManagementAccountFilter $filter): Builder
    {
        $query = $this->managementAccounts();

        if ($filter->active !== null) {
            $query->where('u.is_active', $filter->active);
        }

        if ($filter->role instanceof UserRole) {
            $query->whereExists($this->rolesOfAccount([$filter->role->value]));
        }

        if ($filter->search !== null && $filter->search !== '') {
            $pattern = '%'.addcslashes($filter->search, '\\%_').'%';

            // El grupo anidado combina la busqueda con `AND` con los demas
            // filtros; sin el, el `OR` se aplicaria al mismo nivel.
            $query->where(static function (Builder $group) use ($pattern): void {
                $group->whereRaw('unaccent(u.name) ILIKE unaccent(?)', [$pattern])
                    ->orWhereRaw('unaccent(u.email::text) ILIKE unaccent(?)', [$pattern]);
            });
        }

        return $query;
    }

    private function managementAccounts(): Builder
    {
        return DB::table('users as u')->whereExists($this->rolesOfAccount(array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::managementRoles(),
        )));
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function rolesOfAccount(array $roleNames): Builder
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->whereColumn('mr.model_id', 'u.id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->whereIn('r.name', $roleNames)
            ->selectRaw('1');
    }

    /**
     * Las columnas de la fila y los dos agregados. El tipo del modelo va como
     * parametro enlazado, nunca interpolado en el SQL.
     */
    private function withColumns(Builder $query): Builder
    {
        return $query
            ->select([
                'u.uuid',
                'u.name',
                'u.email',
                'u.locale',
                'u.is_active',
                'u.temporary_password_expires_at',
                'u.last_login_at',
                'u.created_at',
            ])
            ->selectRaw('(u.two_factor_confirmed_at IS NOT NULL) AS two_factor_enabled')
            ->selectRaw(
                "(SELECT COALESCE(json_agg(r.name ORDER BY r.name), '[]'::json) FROM model_has_roles mr "
                .'JOIN roles r ON r.id = mr.role_id WHERE mr.model_id = u.id AND mr.model_type = ?) AS roles',
                [(new User)->getMorphClass()],
            )
            ->selectRaw(
                "(SELECT COALESCE(json_agg(d.id ORDER BY d.id), '[]'::json) FROM departments d "
                .'WHERE d.manager_user_id = u.id) AS department_ids'
            );
    }

    /**
     * @return list<UserRole>
     */
    private function rolesFrom(mixed $json): array
    {
        $roles = [];

        foreach ($this->jsonList($json) as $name) {
            $role = \is_string($name) ? UserRole::tryFrom($name) : null;

            if ($role instanceof UserRole && $role->isManagementRole()) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    private function toRecord(object $row): ManagementAccountRecord
    {
        /** @var array<string, mixed> $data */
        $data = (array) $row;

        $field = static fn (string $key): mixed => $data[$key] ?? null;

        $roles = $this->rolesFrom($field('roles'));

        return new ManagementAccountRecord(
            uuid: $this->string($field('uuid')),
            name: $this->string($field('name')),
            email: $this->string($field('email')),
            locale: $this->string($field('locale')),
            roles: $roles,
            scope: User::accessScopeFrom($roles, $this->jsonList($field('department_ids'))),
            active: (bool) $field('is_active'),
            twoFactorEnabled: (bool) $field('two_factor_enabled'),
            temporaryPasswordExpiresAt: $this->instant($field('temporary_password_expires_at')),
            lastLoginAt: $this->instant($field('last_login_at')),
            // `created_at` es `NOT NULL` en `users`: el respaldo no se alcanza.
            createdAt: $this->instant($field('created_at')) ?? new DateTimeImmutable('@0'),
        );
    }

    /**
     * @return list<mixed>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = \is_string($value) ? json_decode($value, true) : null;

        return \is_array($decoded) ? array_values($decoded) : [];
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * `TIMESTAMPTZ` a instante en UTC (regla dura 3).
     */
    private function instant(mixed $value): ?DateTimeImmutable
    {
        if (! \is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
    }
}
