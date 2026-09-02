<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use DateTimeImmutable;

/**
 * Lee y escribe el perfil de cumplimiento del centro (RF-PD-07).
 *
 * **Es el puerto de la GESTION, no el del nucleo.** El nucleo recibe los
 * umbrales ya resueltos y en minutos por `Shared\Application\Port\
 * CompliancePolicyProvider` (regla dura 14) y no conoce este. Aqui vive lo que
 * el panel necesita —el nombre, el identificador, como se resolvio— y lo que
 * hace falta para escribir un asiento con el valor anterior.
 */
interface ComplianceProfileRepository
{
    /**
     * El perfil vigente para el centro: el asignado, o el de `is_default`.
     *
     * `null` significa que la instalacion no tiene ningun perfil, lo que solo
     * puede pasar si alguien borro la fila que siembra la migracion. No se cae en
     * ningun valor por defecto de codigo: un umbral legal escondido en PHP es
     * indistinguible de uno configurado hasta que alguien compara una alerta con
     * el convenio.
     */
    public function forSite(int $siteId): ?ComplianceProfileSnapshot;

    /**
     * Lo mismo, **sin pasar por ninguna memoria ni cache**, para leer dentro de
     * la transaccion que va a escribir.
     *
     * Existe por la misma razon que `storedValuesForWrite()` en la configuracion
     * de la instalacion: el asiento declara un valor anterior, y leerlo de una
     * copia podria declarar un valor que nunca rigio.
     */
    public function forSiteForWrite(int $siteId): ?ComplianceProfileSnapshot;

    /**
     * Si **otro** perfil ya usa ese nombre.
     *
     * `compliance_profiles.name` es unico. Preguntarlo aqui, dentro de la
     * transaccion que va a escribir, es lo que convierte una violacion de
     * restriccion —un `500` sin explicacion— en un `422` que señala el campo.
     */
    public function nameIsTakenByAnotherProfile(int $profileId, string $name): bool;

    /**
     * Deja escrito el perfil completo, con la marca de quien lo cambio y cuando.
     *
     * Recibe el perfil ya validado y escribe **todas** sus columnas editables:
     * quien decide que cambia es el caso de uso, que es el unico que ha leido el
     * estado anterior bajo el candado.
     *
     * `$actorUserId` a `null` es la consola y el instalador: no hay sesion
     * detras y no se inventa una. El «quien» con valor probatorio esta en
     * `audit_log`; esto es lo que permite saber, mirando la fila, si el perfil
     * sigue siendo el de serie.
     */
    public function save(ComplianceProfileSnapshot $profile, ?int $actorUserId, DateTimeImmutable $at): void;
}
