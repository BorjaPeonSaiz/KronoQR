<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Site;

/**
 * El centro de trabajo de la instalacion.
 *
 * **Hay exactamente uno** (ADR-040): por eso no hay `all()` ni `findById()`. Lo
 * que un caso de uso necesita es *el* centro —para adscribirle un alta o para
 * resolver su zona horaria—, y `installationSite()` es la unica lectura.
 *
 * `add()` devuelve el centro **con su identificador ya asignado** en lugar de
 * `void`: la clave la pone la base de datos y el caso de uso la necesita para
 * responder. Solo puede tener exito una vez por instalacion.
 */
interface SiteRepository
{
    /**
     * @throws SiteAlreadyConfigured si la instalacion ya tiene su centro (indice `sites_single_row_uidx`)
     * @throws SiteNameAlreadyTaken
     */
    public function add(Site $site): Site;

    /**
     * @throws SiteNameAlreadyTaken
     */
    public function save(Site $site): void;

    /**
     * `null` solo antes de la puesta en marcha (RF-PD-03).
     */
    public function installationSite(): ?Site;

    /**
     * El centro con su fila bloqueada (`FOR UPDATE`) hasta el final de la
     * transaccion de quien llama (RN-23, ADR-056).
     *
     * Es lo que hace que el cambio de zona y el primer fichaje no se crucen:
     * `FOR UPDATE` choca con el `FOR KEY SHARE` con que el fichaje lee la zona
     * (`SiteCalendar::timezoneForRecording()`), asi que uno de los dos espera
     * al otro. Fuera de una transaccion no protege nada.
     *
     * `null` solo antes de la puesta en marcha.
     */
    public function installationSiteForUpdate(): ?Site;
}
