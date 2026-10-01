<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * El relevo del token de un quiosco, tal como viaja en la respuesta del latido
 * (`rotated_token`, RF-ID-04, ADR-044).
 *
 * **El valor en claro existe solo aqui y en la respuesta.** El servidor guarda
 * su hash; este objeto no se registra, no se serializa en ningun log y no entra
 * en ningun evento. Por eso no tiene `__toString()`.
 */
final readonly class RenewedDeviceToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $value,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Un relevo de token no puede estar vacio.');
        }

        if ($expiresAt->getTimezone()->getName() !== 'UTC') {
            // Regla dura 3.
            throw new InvalidArgumentException('La caducidad del relevo va en UTC.');
        }
    }
}
