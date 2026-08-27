<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UserRole;
use InvalidArgumentException;

/**
 * La cuenta de gestion tal y como sale de la comprobacion de credenciales: sin
 * contrasena, sin clave interna y sin nada que la capa de arriba no necesite.
 *
 * Es lo que devuelve el puerto `UserAccounts` —nombrado aqui en texto y no con
 * un `{@see}`, porque el dominio no importa nada de la capa de aplicacion, ni
 * siquiera en un comentario que el formateador convertiria en `use`—
 * y lo que el caso de uso convierte en sesion. **Nunca un modelo Eloquent**: el
 * caso de uso no puede acabar teniendo a mano `->password` ni `->save()`, que es
 * como una capa de aplicacion termina escribiendo en la base de datos sin pasar
 * por su repositorio.
 *
 * `name` y `email` estan aqui porque el panel los pinta en su cabecera. **No
 * pueden acabar en un log tecnico** (regla dura 21): ahi se identifica a una
 * persona por `uuid` y solo por el.
 */
final readonly class AuthenticatedUser
{
    /**
     * @param  list<UserRole>  $roles  Roles de la cuenta. Vacio es imposible: una cuenta sin rol no puede hacer nada y no deberia poder entrar.
     * @param  list<TokenAbility>  $abilities  Ambitos que llevara su token (§7.3), derivados de los permisos del rol.
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public string $email,
        public string $locale,
        public array $roles,
        public array $abilities,
    ) {
        if ($uuid === '') {
            throw new InvalidArgumentException('AuthenticatedUser necesita el UUID publico de la cuenta.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('AuthenticatedUser necesita el nombre de la cuenta.');
        }

        if ($email === '') {
            throw new InvalidArgumentException('AuthenticatedUser necesita el correo de la cuenta.');
        }

        if ($locale === '') {
            throw new InvalidArgumentException('AuthenticatedUser necesita el idioma de la cuenta.');
        }

        if ($roles === []) {
            // Una cuenta sin rol es un fallo de aprovisionamiento, no un caso de
            // uso: entraria al panel y recibiria 403 en cada pantalla, que es la
            // forma mas cara de descubrir que falto un paso al crearla.
            throw new InvalidArgumentException('AuthenticatedUser necesita al menos un rol (RF-ID-02).');
        }
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_map(static fn (UserRole $role): string => $role->value, $this->roles);
    }

    /**
     * @return list<string>
     */
    public function abilityNames(): array
    {
        return array_map(static fn (TokenAbility $ability): string => $ability->value, $this->abilities);
    }
}
