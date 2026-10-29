<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use Illuminate\Cache\RateLimiter;

/**
 * Un limitador que nunca limita, con los limitadores con nombre del original.
 *
 * Para los barridos que piden un centenar de operaciones con el mismo token
 * dentro de una prueba (`403`, `400`, `415`): el limitador va antes de la
 * autorizacion en varias zonas, y el `429` de una zona pequena —`2fa`, por
 * IP— taparia el codigo que se mide. Los cupos tienen sus propias pruebas; el
 * barrido del `429` usa {@see ExhaustedRateLimiter}.
 */
final class OpenRateLimiter extends RateLimiter
{
    public function __construct(RateLimiter $original)
    {
        parent::__construct($original->cache);
        $this->limiters = $original->limiters;
    }

    public function tooManyAttempts($key, $maxAttempts): bool
    {
        return false;
    }
}
