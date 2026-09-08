<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\Model\SupportGrant;

/**
 * Lo que devuelve conceder un acceso: la concesion y **su token en claro**
 * (RF-PD-11, esquema `IssuedSupportGrant` del contrato).
 *
 * ## Es la unica vez que el token existe fuera de Sanctum
 *
 * Viaja de aqui a la respuesta `201` o a la salida del comando y se olvida. No
 * se registra, no se guarda y no se puede volver a pedir: la fila solo tiene su
 * hash. Si se pierde, se revoca la concesion y se crea otra — que es una molestia
 * de treinta segundos y la unica alternativa que no deja el token escrito en
 * algun sitio del que alguien pueda leerlo despues.
 *
 * **No se registra en ningun log**, y por eso este objeto no tiene `__toString()`
 * ni `toArray()`: un volcado accidental de la respuesta de un caso de uso es
 * exactamente como se filtra un secreto.
 */
final readonly class IssuedSupportGrant
{
    public function __construct(
        public SupportGrant $grant,
        /** El token en claro. Ver el docblock antes de tocarlo. */
        public string $token,
    ) {}
}
