<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use RuntimeException;

/**
 * La cuenta que `--responsible` nombra para la purga no existe o no esta
 * activa (RF-PR-03, RL-04).
 *
 * El asiento de una purga firma con esa cuenta: una que no existe o que esta
 * dada de baja dejaria en `audit_log` un responsable que no responde de nada.
 * Se comprueba en la simulacion, antes de borrar, y otra vez con la cadena de
 * auditoria tomada justo antes del asiento, igual que en la correccion de la
 * zona del centro; asi nadie puede darla de baja entre la comprobacion y la
 * firma. Sin roles: quien puede responder de una purga es una decision
 * pendiente del propietario.
 */
final class RetentionResponsibleNotActive extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct(
            'La cuenta '.$userId.' de --responsible no existe o no esta activa. No se ha borrado nada.',
        );
    }
}
