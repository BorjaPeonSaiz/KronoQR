<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\CredentialSecretFactory;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;

/**
 * Los 128 bits de aleatoriedad de un token de credencial (doc 02 §5.1).
 *
 * **`random_bytes()` y ningun otro generador.** Es el unico de PHP respaldado
 * por el generador criptografico del sistema operativo. Con `mt_rand`, `rand`,
 * `uniqid` o `Str::random`, quien recopile unos cuantos tokens —le basta con
 * pedir varias tarjetas— puede reconstruir el estado del generador y predecir
 * los siguientes, y entonces los 128 bits del §5.1 no protegen de nada.
 *
 * `random_bytes()` lanza si el sistema no puede darle entropia, y **eso es lo
 * correcto**: emitir una credencial con aleatoriedad degradada es peor que no
 * emitirla.
 */
final readonly class RandomCredentialSecretFactory implements CredentialSecretFactory
{
    public function next(): CredentialSecret
    {
        return CredentialSecret::fromBytes(random_bytes(CredentialSecret::ENTROPY_BYTES));
    }
}
