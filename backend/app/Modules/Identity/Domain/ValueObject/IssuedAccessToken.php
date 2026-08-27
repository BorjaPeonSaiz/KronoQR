<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * El token que se acaba de emitir, con su caducidad.
 *
 * Lleva el valor **en claro** porque es la unica vez que existe: el servidor
 * guarda su hash (`personal_access_tokens.token`) y no puede volver a
 * enseñarlo. Por eso este objeto no se registra en ningun log ni se guarda en
 * ningun sitio: se serializa en la respuesta y se olvida.
 */
final readonly class IssuedAccessToken
{
    public function __construct(
        public string $plainTextToken,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($plainTextToken === '') {
            throw new InvalidArgumentException('Un token emitido no puede estar vacio.');
        }

        if ($expiresAt->getTimezone()->getName() !== 'UTC') {
            // Regla dura 3. Una caducidad en hora local caduca a la hora
            // equivocada dos veces al ano.
            throw new InvalidArgumentException('La caducidad del token va en UTC.');
        }
    }
}
