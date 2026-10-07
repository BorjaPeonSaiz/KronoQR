<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

/**
 * La invariante de la baja de una cuenta de gestion: **siempre queda alguien
 * capaz de gestionar la instalacion** (**RF-ID-10**).
 *
 * ## Las dos bajas que no se admiten
 *
 * - **La propia cuenta.** Quien se da de baja a si mismo pierde la sesion desde
 *   la que podria deshacerlo, y la baja de un `admin` la decide otro `admin`.
 * - **La ultima cuenta `admin` activa.** Dejaria la instalacion sin nadie capaz
 *   de gestionar cuentas ni configuracion, y la unica salida seria una consola
 *   en el servidor del cliente — justo lo que el panel de cuentas existe para
 *   evitar.
 *
 * **Es invariante, no regla de pantalla**: la aplican la API y la consola.
 * Quien llama la consulta **bajo candado** —el del padron de cuentas y la fila
 * de la cuenta—, porque dos `admin` que se dan de baja el uno al otro a la vez
 * cumplen los dos la regla por separado y la rompen juntos.
 *
 * Pura y sin estado: recibe los hechos ya leidos y devuelve un veredicto. No sabe
 * de base de datos ni de candados.
 */
final class ManagementAccountDeactivationGuard
{
    /**
     * @param  string|null  $actorUuid  Quien da de baja. `null` en consola: alli no hay
     *                                  sesion, asi que nunca es «la propia cuenta».
     * @param  string  $targetUuid  La cuenta que se quiere dar de baja.
     * @param  bool  $targetIsActiveAdmin  Si esa cuenta es `admin` y esta activa.
     * @param  int  $activeAdmins  Cuantas cuentas `admin` activas hay, contando la objetivo.
     */
    public static function decide(
        ?string $actorUuid,
        string $targetUuid,
        bool $targetIsActiveAdmin,
        int $activeAdmins,
    ): DeactivationVerdict {
        if ($actorUuid !== null && $actorUuid === $targetUuid) {
            return DeactivationVerdict::OwnAccount;
        }

        if ($targetIsActiveAdmin && $activeAdmins <= 1) {
            return DeactivationVerdict::LastActiveAdmin;
        }

        return DeactivationVerdict::Allowed;
    }
}
