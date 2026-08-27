<?php

declare(strict_types=1);

namespace KronoQR\Tools\Quality;

/**
 * Lo que mide {@see DomainCoverageGate}: el porcentaje del subarbol, el umbral
 * que se le exige y el desglose por fichero, para que un fallo diga cual es el
 * fichero que falta y no solo que el numero no llega.
 */
final readonly class CoverageResult
{
    /**
     * @param  array<string, float>  $files  Ruta relativa => porcentaje.
     */
    public function __construct(
        public int $coveredStatements,
        public int $statements,
        public float $percentage,
        public float $minimum,
        public array $files,
    ) {}

    public function passes(): bool
    {
        return $this->percentage >= $this->minimum;
    }

    /**
     * Los ficheros que no llegan al umbral, de peor a mejor.
     *
     * @return array<string, float>
     */
    public function below(): array
    {
        $below = array_filter($this->files, fn (float $percentage): bool => $percentage < $this->minimum);

        asort($below);

        return $below;
    }
}
