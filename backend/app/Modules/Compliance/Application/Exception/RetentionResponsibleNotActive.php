<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use RuntimeException;

/**
 * La cuenta que `--responsible` nombra para la purga no existe, no esta activa
 * o no tiene rol admin o rrhh (RF-PR-03, RL-04).
 *
 * El asiento de una purga firma con esa cuenta: una que no existe o que esta
 * dada de baja dejaria en `audit_log` un responsable que no responde de nada.
 * La consola lo comprueba antes de simular o de borrar, y el caso de uso otra
 * vez con la cadena de auditoria tomada justo antes del asiento, igual que en
 * la correccion de la zona del centro; asi nadie puede darla de baja entre la
 * comprobacion y la firma. Los roles los decidio el propietario el 18-10-2026
 * (doc 01 RF-PR-03, §7.2 «Firmas por consola»).
 */
final class RetentionResponsibleNotActive extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct(
            'The --responsible account is no longer an active management account that can sign the purge. Nothing is purged.',
        );
    }
}
