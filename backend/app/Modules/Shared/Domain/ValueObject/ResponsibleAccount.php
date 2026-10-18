<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * La cuenta de gestion a la que apunta un `--responsible`, tal como esta en la
 * base en el momento de leerla (2.3.0 bloque 3).
 *
 * Lleva lo que la consola enseña antes de confirmar —nombre de la cuenta,
 * correo, roles y `uuid`— y lo que decide si puede firmar: si esta activa y
 * sus roles. **El nombre es el de la cuenta de gestion, no el de un empleado**,
 * y solo se imprime en la terminal de quien firma: nunca va al log tecnico
 * (regla dura 21), donde basta el `uuid`.
 *
 * El id interno solo sirve para que el caso de uso vuelva a comprobar la
 * cuenta con la cadena de `audit_log` tomada y para el `actor_id` del asiento;
 * no se enseña ni se pide.
 */
final readonly class ResponsibleAccount
{
    /**
     * @param  list<UserRole>  $roles
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        public string $email,
        public bool $isActive,
        public array $roles,
    ) {}

    /**
     * Si puede firmar una accion que exige alguno de `$roles`. Lista vacia:
     * cualquier rol de gestion.
     *
     * @param  list<UserRole>  $roles
     */
    public function canSignAs(array $roles): bool
    {
        if (! $this->isActive) {
            return false;
        }

        if ($roles === []) {
            return array_any($this->roles, static fn (UserRole $role): bool => $role->isManagementRole());
        }

        return array_any($this->roles, static fn (UserRole $role): bool => \in_array($role, $roles, true));
    }

    /** Si el correo de confirmacion es el de esta cuenta, sin distinguir mayusculas (`citext`). */
    public function isConfirmedBy(string $email): bool
    {
        return $email !== '' && mb_strtolower(trim($email)) === mb_strtolower($this->email);
    }
}
