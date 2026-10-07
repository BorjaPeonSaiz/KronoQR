<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Identity\Application\Exception\ManagementSessionVanished;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;

/**
 * Quien actua sobre otra cuenta **sigue activo en el momento de escribir**
 * (RF-ID-10; tiempo de comprobacion frente a tiempo de uso).
 *
 * La sesion se comprobo al entrar en la peticion, pero entre eso y la
 * escritura otra `admin` puede haberle dado de baja: sin esta relectura, una
 * cuenta ya dada de baja completaria su propia operacion —una baja, un alta,
 * un restablecimiento— un instante despues de perder el acceso.
 *
 * Se llama **con la cadena tomada** (y en la baja, tambien el padron de
 * cuentas): como toda baja escribe con la cadena en la mano, lo que se lee
 * aqui no puede cambiar hasta el commit. La fila del actor se toma con el mismo
 * `FOR NO KEY UPDATE` que la de la cuenta objetivo. Si ya no esta activa, se
 * aborta sin escribir nada y sin asiento; la capa HTTP responde `401`, como a
 * cualquier sesion que ha dejado de valer. `null` (consola) no se comprueba:
 * alli no hay sesion.
 */
final class ActingAccountCheck
{
    /**
     * @throws ManagementSessionVanished si la cuenta que actua ya no existe o esta de baja
     */
    public static function assertStillActive(ManagementAccountLifecycle $accounts, ?string $actorUuid): void
    {
        if ($actorUuid === null) {
            return;
        }

        if ($accounts->lockAccount($actorUuid)?->active !== true) {
            throw new ManagementSessionVanished;
        }
    }
}
