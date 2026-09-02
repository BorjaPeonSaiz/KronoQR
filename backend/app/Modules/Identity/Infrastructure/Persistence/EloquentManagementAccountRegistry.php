<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\ManagementAccountRegistry;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * Alta de cuentas de gestion sobre Eloquent y `spatie/laravel-permission`.
 *
 * ## «Cuenta de gestion» son los cuatro roles de RF-ID-02, no cualquier fila de `users`
 *
 * `empleado` y `kiosk` estan en el catalogo de roles pero **no abren sesion en
 * el panel**: el empleado entra a su portal con codigo y PIN (ADR-015) y el
 * quiosco con un token de dispositivo (RF-ID-04). Contarlos como cuenta de
 * gestion cerraria la puerta del primer administrador en cuanto la instalacion
 * tuviera un quiosco vinculado, que puede ocurrir antes si alguien empieza el
 * asistente por ahi.
 *
 * ## Activas y desactivadas
 *
 * A proposito. Contar solo las activas convertiria «dar de baja a la unica
 * persona con acceso» en «reabrir la creacion publica de un administrador».
 *
 * ## La contrasena se hashea aqui
 *
 * El modelo `User` la castea a `hashed`, asi que el valor en claro no llega a
 * escribirse ni a leerse nunca; llega a este metodo y muere en la insercion.
 *
 * ## Devuelve el mismo objeto de valor que {@see UserAccounts}
 *
 * Y por la misma razon: hacia arriba no sale ningun modelo Eloquent. Se relee la
 * cuenta con {@see UserAccounts::findByUuid()} en vez de componer el objeto a
 * mano, para que los ambitos y el alcance salgan del **mismo** calculo que usa
 * el acceso — si se compusieran aqui, un permiso nuevo del rol tendria que
 * acordarse de aparecer en dos sitios.
 */
final readonly class EloquentManagementAccountRegistry implements ManagementAccountRegistry
{
    public function __construct(private UserAccounts $accounts) {}

    public function anyManagementAccountExists(): bool
    {
        $managementRoles = array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::managementRoles(),
        );

        return User::query()
            ->whereHas('roles', static function (Builder $query) use ($managementRoles): void {
                $query->whereIn('name', $managementRoles);
            })
            ->exists();
    }

    public function create(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
        string $locale,
        UserRole $role,
    ): AuthenticatedUser {
        // UUID v7 y no v4, como el resto del producto: es ordenable
        // temporalmente y mantiene la localidad de los indices que lo
        // referencian (doc 02 §6).
        $uuid = Str::uuid7()->toString();

        $user = User::query()->create([
            'uuid' => $uuid,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->assignRole($role->value);

        $created = $this->accounts->findByUuid($uuid);

        if (! $created instanceof AuthenticatedUser) {
            // Se acaba de escribir en esta misma transaccion: no encontrarla no
            // es un caso de negocio, es una incoherencia. Se rompe en lugar de
            // devolver una cuenta a medias, que dejaria emitir un reto sobre
            // algo que no se sabe que es.
            throw new RuntimeException('La cuenta de gestion recien creada no se ha podido releer.');
        }

        return $created;
    }
}
