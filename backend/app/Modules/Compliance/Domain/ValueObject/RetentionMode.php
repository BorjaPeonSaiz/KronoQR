<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Los dos modos de `compliance:apply-retention` (RF-PR-03).
 *
 * `Simulation` es el modo por defecto y el unico que se programa: **propone**.
 * `Execution` solo se alcanza con la frase de confirmacion que imprime la
 * simulacion, porque una purga es la unica eliminacion legitima de datos del
 * sistema (regla dura 5) y no puede ocurrir por inercia de un cron.
 */
enum RetentionMode: string
{
    case Simulation = 'simulation';

    case Execution = 'execution';

    public function isSimulation(): bool
    {
        return $this === self::Simulation;
    }

    public function label(): string
    {
        return $this === self::Simulation ? 'SIMULACION (no se ha borrado nada)' : 'EJECUCION REAL';
    }
}
