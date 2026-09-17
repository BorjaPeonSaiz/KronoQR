<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\ComplianceProfileRef;

/**
 * **Como se llama** el perfil de cumplimiento del centro (RF-PA-06, paso 5 de la
 * ficha).
 *
 * ## Por que no lo da `CompliancePolicyProvider`
 *
 * Porque aquel puerto responde a otra pregunta. `CompliancePolicy` son los
 * **umbrales ya resueltos en minutos** y nada mas: no lleva identificador ni
 * nombre a proposito, para que ninguna regla pueda escribir «si el perfil es
 * ES-hosteleria, entonces…» (regla dura 13, ADR-017). Aqui lo unico que se pide
 * es el rotulo con el que la pantalla defiende el aviso —«12 h segun el perfil
 * ES-hosteleria»—, y eso es presentacion, no umbral.
 *
 * Meter el nombre en `CompliancePolicy` habria abierto esa puerta en el nucleo de
 * la deteccion de incidencias para que una vista pudiera pintar un rotulo.
 *
 * ## La cascada es la misma
 *
 * Perfil asignado al centro (`sites.compliance_profile_id`) y, si no tiene, el
 * perfil por defecto de la instalacion (`compliance_profiles.is_default`), igual
 * que `DbCompliancePolicyProvider`. Tienen que ser la misma, o la pantalla
 * nombraria un perfil distinto del que ha puesto los umbrales.
 */
interface ComplianceProfileReference
{
    /**
     * `null` cuando no hay perfil asignado ni perfil por defecto — un estado que
     * solo alcanza una instalacion con la tabla editada a mano, y que el caso de
     * uso no puede servir: sin perfil no hay umbrales que enseñar.
     */
    public function forSite(int $siteId): ?ComplianceProfileRef;
}
