<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Spatie\Permission\Models\Permission;

/**
 * Las cuentas de gestion sobre Eloquent y `bcrypt`.
 *
 * Traduce entre la fila de `users` y el objeto de valor {@see AuthenticatedUser}
 * que consume el caso de uso: hacia arriba no sale ningun modelo Eloquent, que
 * es lo que impide que la capa de aplicacion acabe con `->password` y
 * `->save()` a mano.
 */
final readonly class EloquentUserAccounts implements UserAccounts
{
    /**
     * Hash de descarte con el que se compara cuando la cuenta no existe.
     *
     * **No es paranoia: es el mismo control que RS-03 impone en el fichaje.**
     * Sin esto, un correo desconocido responde en microsegundos y uno existente
     * tarda lo que tarda `bcrypt`, de modo que cualquiera puede enumerar quien
     * trabaja aqui midiendo el tiempo de respuesta. Es un hash valido de una
     * cadena que nadie conoce, generado una vez y fijo.
     */
    private const string DUMMY_HASH = '$2y$12$0PQ7pfj2Vt.Xj3Fd0Rj0K.9m5nBcVJ0dnGZ4L1YvKpQmS8xUeVsRe';

    public function verifyCredentials(string $email, string $password): ?AuthenticatedUser
    {
        $user = User::query()
            ->with('roles.permissions')
            ->where('email', $email)
            ->first();

        // Se comprueba SIEMPRE un hash, exista la cuenta o no: es lo que iguala
        // el tiempo de respuesta de los dos casos.
        $matches = Hash::check($password, $user instanceof User ? $user->password : self::DUMMY_HASH);

        if ($user === null || ! $matches || ! $user->is_active) {
            return null;
        }

        $this->rehashIfStale($user, $password);

        return $this->toAuthenticatedUser($user);
    }

    /**
     * Vuelve a hashear la contrasena si el hash guardado se quedo por debajo del
     * coste vigente (hallazgo **H-13** de la revision interna ASVS de 2026-09,
     * OWASP A07).
     *
     * **El coste correcto no sirve de nada si no hay camino para cambiarlo.** El
     * producto usa `bcrypt` con coste 12 y lo tiene probado, pero hasta esta
     * tarea no existia ni un solo `needsRehash` en todo `backend/app`: el dia que
     * 12 se quede corto —y se quedara— la instalacion no tendria forma de migrar
     * los hashes sin un restablecimiento masivo de contrasenas, es decir, sin
     * dejar al cliente sin panel durante una tarde.
     *
     * **Aqui y no en otro sitio, porque este es el unico momento en el que existe
     * la contrasena en claro.** Sin ella no se puede calcular el hash nuevo, asi
     * que la migracion solo puede ocurrir cuando alguien acierta.
     *
     * **Solo en el camino del acceso CORRECTO**, nunca en un rechazo: un rechazo
     * no tiene con que rehashear, y darle trabajo extra a una rama y no a la otra
     * es exactamente la asimetria medible que RS-03 saca del camino de los
     * rechazos.
     *
     * **En la misma transaccion que el acceso**: si quien llama abrio una, esta
     * escritura entra en ella; si no, un `UPDATE` solo ya es atomico. Lo que no
     * ocurre en ningun caso es diferirlo a una cola, que dejaria la contrasena en
     * claro viajando hasta un trabajador.
     *
     * **`save()` y no `update()`**: el cast `hashed` del modelo es quien aplica el
     * algoritmo y el coste configurados, y el constructor de consultas se lo
     * saltaria dejando la contrasena en claro en la columna.
     */
    private function rehashIfStale(User $user, #[SensitiveParameter] string $password): void
    {
        if (! Hash::needsRehash($user->password)) {
            return;
        }

        $user->password = $password;
        $user->save();
    }

    public function findByUuid(string $uuid): ?AuthenticatedUser
    {
        $user = User::query()
            ->with('roles.permissions')
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();

        return $user === null ? null : $this->toAuthenticatedUser($user);
    }

    public function recordSuccessfulLogin(string $uuid, DateTimeImmutable $at): void
    {
        User::query()
            ->where('uuid', $uuid)
            ->update(['last_login_at' => $at]);
    }

    private function toAuthenticatedUser(User $user): AuthenticatedUser
    {
        return new AuthenticatedUser(
            uuid: $user->uuid,
            name: $user->name,
            email: $user->email,
            locale: $user->locale,
            roles: $this->rolesOf($user),
            abilities: $this->abilitiesOf($user),
            // RF-ID-03: el alcance se resuelve al leer la cuenta, no al emitir el
            // token, para que quitarle un departamento a un responsable tenga
            // efecto en la peticion siguiente.
            scope: $user->accessScope(),
            // RS-06: solo si esta CONFIRMADO. Un alta a medias no es un segundo
            // factor y no debe hacer que el acceso se detenga esperando un codigo
            // que nadie puede generar.
            secondFactorActive: $user->two_factor_confirmed_at !== null,
        );
    }

    /**
     * @return list<UserRole>
     */
    private function rolesOf(User $user): array
    {
        $roles = [];

        /** @var mixed $name */
        foreach ($user->getRoleNames() as $name) {
            $role = \is_string($name) ? UserRole::tryFrom($name) : null;

            // Un rol de la base de datos que no esta en el catalogo de RF-ID-02
            // se ignora en lugar de romper la sesion: el catalogo es identico en
            // todas las instalaciones (regla dura 13), asi que si aparece uno
            // ajeno es que alguien lo inserto a mano, y lo correcto es que no
            // conceda nada.
            if ($role instanceof UserRole) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Ambitos del token: los permisos del rol (doc 02 §7.3).
     *
     * Se leen de la base de datos y no de una tabla en codigo para que el reparto
     * de permisos sea uno solo —el que sembro la migracion del catalogo— y no dos
     * que puedan divergir.
     *
     * @return list<TokenAbility>
     */
    private function abilitiesOf(User $user): array
    {
        $abilities = [];

        /** @var Permission $permission */
        foreach ($user->getAllPermissions() as $permission) {
            $ability = TokenAbility::tryFromName($permission->name);

            if ($ability instanceof TokenAbility) {
                $abilities[] = $ability;
            }
        }

        return array_values(array_unique($abilities, SORT_REGULAR));
    }
}
