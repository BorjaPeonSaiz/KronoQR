<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/**
 * El suelo legal de años de conservacion del registro de una jurisdiccion
 * (RL-02, RF-PD-07, regla dura 14, ADR-057 enmienda del 10-10-2026).
 *
 * Lo piden la validacion del perfil de cumplimiento (no admite un plazo menor),
 * la politica de retencion (no purga por debajo) y la conciliacion del registro
 * con su auditoria (ADR-057 §4), que descarta un asiento de purga que dice
 * haber conservado menos de lo que la ley permite. Es el **suelo**, no el plazo
 * vigente: un plazo subido despues de una purga legitima no puede convertirla
 * en sospechosa.
 *
 * Puerto en `Shared` y adaptador en `Product`, que es quien define el perfil y
 * a quien `Compliance` no puede importar (doc 02 §1.6). El catalogo vive en
 * `config/compliance.php` (`retention_floors`): es un dato legal del producto,
 * no del cliente (regla dura 13).
 */
interface RetentionYearsFloor
{
    /** El suelo del catalogo `compliance.retention_floors` para esa jurisdiccion, o `default`. */
    public function forJurisdiction(Jurisdiction $jurisdiction): LegalRetentionFloor;

    /**
     * El de la jurisdiccion del perfil vigente del centro: el asignado o, si no
     * tiene, el de `is_default`, igual que CompliancePolicyProvider::forSite().
     */
    public function forSite(int $siteId): LegalRetentionFloor;
}
