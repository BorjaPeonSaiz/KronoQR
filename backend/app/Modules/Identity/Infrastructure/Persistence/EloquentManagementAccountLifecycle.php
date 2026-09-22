<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use RuntimeException;
use SensitiveParameter;

/**
 * La baja de una cuenta de gestion y la sustitucion de su contrasena, sobre
 * Eloquent (RS-05, RS-06, RL-16).
 *
 * ## El correo se busca aqui y no sale de aqui
 *
 * Los dos metodos de busqueda reciben el correo y devuelven el `uuid` publico o
 * un booleano: **ninguna direccion cruza hacia la capa de aplicacion**, que es
 * lo que impide que acabe en un evento de dominio y de ahi en `audit_log` o en
 * un log tecnico (regla dura 21).
 *
 * ## La contrasena se hashea aqui
 *
 * El modelo `User` la castea a `hashed`, asi que el valor en claro no llega a
 * escribirse ni a leerse nunca: entra en este metodo y muere en el `save()`. Es
 * el mismo camino por el que pasa el alta en
 * {@see EloquentManagementAccountRegistry}, y por eso el coste de `bcrypt` es
 * uno solo y esta en un sitio.
 *
 * ## `save()` y no `update()` en la contrasena
 *
 * `Builder::update()` escribe el valor tal cual y **se salta los casts**: una
 * contrasena en claro en la columna, sin que nada fallara. El hash lo aplica el
 * modelo, asi que la escritura tiene que pasar por el.
 */
final readonly class EloquentManagementAccountLifecycle implements ManagementAccountLifecycle
{
    public function uuidOfActiveAccount(string $email): ?string
    {
        $uuid = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->value('uuid');

        return \is_string($uuid) ? $uuid : null;
    }

    public function accountExists(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    public function deactivate(string $uuid): void
    {
        User::query()
            ->where('uuid', $uuid)
            ->update(['is_active' => false]);
    }

    public function replacePassword(string $uuid, #[SensitiveParameter] string $password): void
    {
        $user = User::query()->where('uuid', $uuid)->first();

        if (! $user instanceof User) {
            // El caso de uso acaba de resolver el `uuid` en esta misma peticion:
            // no encontrarla no es un caso de negocio, es una incoherencia. Se
            // rompe la transaccion —y con ella el asiento— en lugar de dejar en
            // `audit_log` una sustitucion de contrasena que no ocurrio.
            throw new RuntimeException('La cuenta de gestion a la que se iba a cambiar la contrasena no existe.');
        }

        $user->password = $password;
        $user->save();
    }
}
