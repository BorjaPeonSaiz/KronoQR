<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Lo que devuelve {@see RotateSigningKey}: cuantas tarjetas quedan por
 * reimprimir y cuantas reemisiones ha creado este acto (RF-QR-07).
 *
 * **No lleva ninguna clave ni ningun nombre**, solo los dos `key_id` —que van
 * impresos en cada tarjeta y por tanto no son secretos— y recuentos. Es lo que
 * pinta el comando en la terminal y lo que da el aviso «faltan 60 por
 * reimprimir».
 */
final readonly class SigningKeyRotationReport
{
    public function __construct(
        /** La clave que deja de firmar. */
        public string $retiringKeyId,
        /** La clave con la que se firmara todo lo que se imprima desde ahora. */
        public string $currentKeyId,
        /** Tarjetas escaneables que todavia lleva la clave saliente. */
        public int $cardsOnRetiringKey,
        /** Reemisiones creadas en este acto, pendientes de imprimir. */
        public int $reissued,
        /** Personas que ya tenian una reemision pendiente y no se han duplicado. */
        public int $alreadyPending,
        /** Si solo se ha informado, sin escribir nada. */
        public bool $dryRun,
    ) {}
}
