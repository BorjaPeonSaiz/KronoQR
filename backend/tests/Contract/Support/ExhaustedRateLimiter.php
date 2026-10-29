<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use Illuminate\Cache\RateLimiter;

/**
 * Un limitador con todas las claves agotadas: cualquier pregunta de «¿ha
 * superado el limite?» responde que si, y el cupo vuelve en 30 s.
 *
 * Existe para el barrido del `429` (Q3): provocarlo de verdad exigiria conocer
 * la clave y el cupo de cada zona y gastar decenas de peticiones por operacion,
 * y lo que el barrido mide no es el cupo —eso lo prueban `ScanRateLimitTest`,
 * `ManagementRateLimitTest` y compania— sino que la ruta **pasa** por un
 * limitador y que su `429` tiene la forma que declara el contrato.
 *
 * Conserva los limitadores con nombre del original (`RateLimiter::for`), que
 * son los que deciden la clave y, en algunas zonas, si la peticion se limita
 * siquiera: sustituirlo por uno vacio haria que `throttle:management` dejara de
 * ser una zona con nombre y midiera otra cosa.
 */
final class ExhaustedRateLimiter extends RateLimiter
{
    public const int RETRY_AFTER = 30;

    public function __construct(RateLimiter $original)
    {
        parent::__construct($original->cache);
        $this->limiters = $original->limiters;
    }

    public function tooManyAttempts($key, $maxAttempts): bool
    {
        return true;
    }

    public function availableIn($key): int
    {
        return self::RETRY_AFTER;
    }
}
