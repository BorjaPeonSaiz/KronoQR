<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Workforce\Application\Port\EmployeeCodeGenerator;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;
use Random\RandomException;

/**
 * Los caracteres aleatorios de un codigo de empleado nuevo, con el generador
 * criptografico del sistema (H8).
 *
 * `random_int()` lanza si el sistema no puede darle entropia, y **eso es lo
 * correcto**: un alta con un codigo predecible es peor que un alta que falla.
 */
final readonly class RandomEmployeeCodeGenerator implements EmployeeCodeGenerator
{
    /**
     * @throws RandomException
     */
    public function next(): EmployeeCode
    {
        $picks = [];

        for ($i = 0; $i < EmployeeCode::RANDOM_LENGTH; $i++) {
            $picks[] = random_int(0, EmployeeCode::ALPHABET_SIZE - 1);
        }

        return EmployeeCode::fromRandomPicks($picks);
    }
}
