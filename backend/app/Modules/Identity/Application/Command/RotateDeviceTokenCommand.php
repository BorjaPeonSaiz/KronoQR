<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * La orden de rotar el token de un quiosco si toca (RF-ID-04, ADR-044).
 *
 * `presentedTokenId` es el token que firmo el latido, y no un detalle: la
 * politica decide por el firmante. Un latido firmado con el token vigente puede
 * abrir una rotacion; uno firmado con un token ya relevado solo puede pedir que
 * se le reentregue el relevo que no llego, nunca alargar su propio solape.
 */
final readonly class RotateDeviceTokenCommand
{
    public function __construct(
        public string $deviceUuid,
        public int $presentedTokenId,
    ) {}
}
