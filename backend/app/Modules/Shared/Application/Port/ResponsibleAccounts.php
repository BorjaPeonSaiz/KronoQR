<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede responder de una accion de consola con relevancia legal: la
 * cuenta de gestion que se declara con `--responsible` y que firma el asiento.
 *
 * **Vive en `Shared`** porque la necesitan modulos que no se importan entre
 * si: `Compliance` (purga y revisiones, ADR-057), `Workforce` (correccion de
 * la zona horaria, RN-23, ADR-056 §3), `Identity` (entrega de una tarjeta,
 * RF-QR-06) y `Product` (paquete con datos personales, RL-19). Con una copia
 * en cada uno, la primera correccion de la consulta habria llegado solo a una.
 *
 * **Los roles los decide quien llama**, y desde la 2.3.0 (doc 01 §7.2,
 * «Firmas por consola») todas las ordenes exigen los que hacen lo mismo desde
 * el panel: `admin` o `rrhh` para la zona, la purga, las revisiones y la
 * entrega de una tarjeta; solo `admin` para el paquete con datos personales.
 * La consola no es la via por la que alguien hace lo que el panel le niega.
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

    /**
     * La cuenta a la que apunta `--responsible` —por `uuid` o por correo—,
     * este activa o no, para que la consola la enseñe antes de confirmar y
     * diga por que no puede firmar. `null` si no hay ninguna
     * cuenta con ese `uuid` o ese correo.
     *
     * No sustituye a {@see self::isActiveManagementAccount()}: lo leido aqui
     * sirve para preguntar; la comprobacion que cuenta es la que el caso de uso
     * repite con la cadena tomada.
     */
    public function find(ResponsibleReference $reference): ?ResponsibleAccount;
}
