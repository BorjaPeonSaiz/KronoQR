<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Como se ha resuelto el perfil de cumplimiento del centro.
 *
 * `sites.compliance_profile_id` es **nullable** y significa «usa el perfil por
 * defecto de la instalacion». Los dos caminos llevan al mismo sitio, pero quien
 * mira la pantalla necesita saber cual: un centro sin perfil asignado hereda los
 * cambios que se hagan al perfil por defecto, y uno con perfil propio no.
 */
enum ComplianceProfileSource: string
{
    /** El centro tiene perfil asignado. */
    case Site = 'site';

    /** El centro no tiene perfil y rige el de `is_default`. */
    case InstallationDefault = 'installation_default';
}
