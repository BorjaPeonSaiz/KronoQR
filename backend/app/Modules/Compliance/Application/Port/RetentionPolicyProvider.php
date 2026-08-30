<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionPolicySnapshot;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;

/**
 * Sirve al caso de uso los plazos de retencion **ya resueltos** (regla dura 14,
 * RL-11, RF-PD-07).
 *
 * **Por que hay un puerto de `Compliance` habiendo ya `CompliancePolicyProvider`
 * en `Shared`.** Porque este entrega otra cosa: aquel devuelve los cuatro
 * umbrales legales del centro —descanso, jornada, pausa y anos de retencion— y
 * este devuelve la **politica de retencion por tipo de dato**, que ademas de los
 * anos del perfil incluye los dias del log tecnico y del historico de errores,
 * que no son legales ni salen de `compliance_profiles` sino de la configuracion
 * de la instalacion (doc 02 §8.2.1). Juntar las dos fuentes es trabajo del
 * adaptador, no del caso de uso; si el caso de uso lo hiciera, tendria que saber
 * de donde viene cada plazo, que es justo lo que la regla dura 14 evita.
 *
 * **El centro no se pide como parametro** (ADR-040): hay exactamente uno por
 * instalacion y lo resuelve el adaptador con `InstallationSiteProvider`. Un
 * `forSite(int $siteId)` aqui invitaria a construir multi-centro por la puerta
 * de atras y obligaria a la consola a elegir un centro que no tiene forma de
 * conocer.
 */
interface RetentionPolicyProvider
{
    /**
     * @throws InstallationSiteMissing si la
     *                                 instalacion todavia no tiene centro (RF-PD-03): sin perfil de
     *                                 cumplimiento no hay plazo, y sin plazo no se purga nada.
     */
    public function forInstallation(): RetentionPolicy;

    /**
     * Los mismos plazos, con el centro del que salieron, para dejarlos escritos
     * en el informe y en el asiento (bloque E de `/revision-cumplimiento`).
     */
    public function snapshot(): RetentionPolicySnapshot;
}
