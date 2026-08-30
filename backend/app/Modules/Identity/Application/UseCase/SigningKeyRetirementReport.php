<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Lo que devuelve {@see RetireSigningKey} cuando el solape se puede cerrar
 * (RF-QR-07).
 *
 * Solo `key_id` y recuento. Ninguna clave sale de la aplicacion por aqui ni por
 * ningun otro sitio.
 */
final readonly class SigningKeyRetirementReport
{
    public function __construct(
        public string $keyId,
        /** Cuantas tarjetas llego a firmar esa clave, todas ellas ya revocadas. */
        public int $signedCredentials,
    ) {}
}
