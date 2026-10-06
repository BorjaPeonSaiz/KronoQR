<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * El portal esta cerrado al origen de esta peticion por demasiados accesos
 * fallidos (RS-12, ADR-050 §2).
 *
 * Se traduce a `429` con `Retry-After` y `urn:kronoqr:problem:portal-origin-locked`.
 * **No es una variante de `PortalAccessDenied`** y no sale como `401`: se decide
 * antes de mirar el codigo y el PIN, asi que no dice nada de ninguna credencial
 * (RS-03), y ocultarlo solo haria que una persona legitima tras la misma red
 * creyera que su PIN esta mal y siguiera tecleando.
 */
final class PortalOriginLocked extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Demasiados accesos fallidos desde esta red. Vuelve a intentarlo mas tarde.');
    }
}
