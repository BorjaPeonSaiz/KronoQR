<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\AccountSnapshot;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * La baja de una cuenta de gestion y la sustitucion de su contrasena, sobre
 * Eloquent (RS-05, RS-06, RL-16, **RF-ID-10**).
 *
 * ## El correo se busca aqui y no sale de aqui
 *
 * {@see self::uuidOfAccount()} recibe el correo y devuelve el `uuid` publico:
 * **ninguna direccion cruza hacia la capa de aplicacion**, que es lo que impide
 * que acabe en un evento y de ahi en `audit_log` o en un log (regla dura 21).
 *
 * ## El hash llega hecho, y por eso `update()` y no `save()`
 *
 * La contrasena se hashea antes, fuera del candado de la cadena (puerto
 * `PasswordHasher`). Aqui se escribe tal cual con el constructor de consultas,
 * que **no pasa por el cast `hashed`** del modelo: es exactamente lo que se
 * quiere, porque el valor ya es un hash. El riesgo de antes —una contrasena en
 * claro en la columna por saltarse el cast— desaparece porque aqui ya no llega
 * ninguna contrasena en claro.
 */
final readonly class EloquentManagementAccountLifecycle implements ManagementAccountLifecycle
{
    public function uuidOfAccount(string $email): ?string
    {
        $uuid = User::query()->where('email', $email)->value('uuid');

        return \is_string($uuid) ? $uuid : null;
    }

    public function lockAccount(string $uuid): ?AccountSnapshot
    {
        $user = User::query()->where('uuid', $uuid)->lockForUpdate()->first();

        if (! $user instanceof User) {
            return null;
        }

        $roles = [];

        /** @var mixed $name */
        foreach ($user->getRoleNames() as $name) {
            $role = \is_string($name) ? UserRole::tryFrom($name) : null;

            if ($role instanceof UserRole) {
                $roles[] = $role;
            }
        }

        return new AccountSnapshot(
            uuid: $user->uuid,
            active: $user->is_active,
            roles: $roles,
            twoFactorConfirmed: $user->two_factor_confirmed_at !== null,
        );
    }

    public function countActiveAdmins(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', static function (Builder $query): void {
                $query->where('name', UserRole::ADMIN->value);
            })
            ->count();
    }

    public function deactivate(string $uuid): void
    {
        User::query()
            ->where('uuid', $uuid)
            ->update(['is_active' => false]);
    }

    public function currentPasswordHash(string $uuid): ?string
    {
        $hash = User::query()
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->value('password');

        return \is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function replacePasswordHash(string $uuid, string $hash, ?DateTimeImmutable $temporaryExpiresAt): void
    {
        $changed = User::query()
            ->where('uuid', $uuid)
            ->update([
                'password' => $hash,
                'temporary_password_expires_at' => $temporaryExpiresAt,
            ]);

        if ($changed !== 1) {
            // El caso de uso acaba de bloquear esta fila: no encontrarla no es un
            // caso de negocio, es una incoherencia. Se rompe la transaccion —y con
            // ella el asiento— en lugar de dejar en `audit_log` un cambio que no
            // ocurrio.
            throw new RuntimeException('La cuenta de gestion a la que se iba a cambiar la contrasena no existe.');
        }
    }
}
