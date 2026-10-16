<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede responder de una accion de consola con relevancia legal: la
 * cuenta de gestion que se declara con `--responsible` y que firma el asiento.
 *
 * **Vive en `Shared`** porque la necesitan dos modulos que no se importan entre
 * si: `Compliance` (revision de una purga, ADR-057 enmienda §6) y `Workforce`
 * (correccion de la zona horaria, RN-23, ADR-056 §3). Con una copia en cada
 * uno, la primera correccion de la consulta habria llegado solo a una.
 *
 * **Los roles los decide quien llama.** La revision de la purga admite
 * cualquier cuenta de gestion activa y
 * pasa la lista vacia; la correccion de la zona exige los roles que pueden
 * modificar el centro por la API, para que la consola no sea la via por la que
 * alguien hace lo que el panel le niega.
 *
 * **Se pregunta con la cadena de `audit_log` tomada** cuando el resultado
 * firma un asiento: la baja de una cuenta toma la cadena antes de tocar su fila
 * (ADR-051 §6), asi que con la cadena en la mano la respuesta no puede quedar
 * vieja antes de confirmar.
 */
interface ResponsibleAccounts
{
    /**
     * Si la cuenta existe, esta activa y —si `$roles` no esta vacia— tiene
     * alguno de esos roles.
     *
     * @param  list<UserRole>  $roles  vacia: cualquier rol
     */
    public function isActiveManagementAccount(int $userId, array $roles = []): bool;
}
