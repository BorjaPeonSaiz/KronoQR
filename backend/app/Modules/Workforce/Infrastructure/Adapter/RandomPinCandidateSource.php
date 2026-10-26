<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Workforce\Application\Port\PinCandidateSource;
use Random\RandomException;

/**
 * Los candidatos a PIN, con el generador criptografico del sistema (RF-ID-09,
 * H8).
 *
 * `random_int()` lanza si el sistema no puede darle entropia, y **eso es lo
 * correcto**: un alta con un PIN predecible es peor que un alta que falla.
 */
final readonly class RandomPinCandidateSource implements PinCandidateSource
{
    /**
     * @throws RandomException
     */
    public function pick(int $maximum): int
    {
        return random_int(0, $maximum);
    }
}
