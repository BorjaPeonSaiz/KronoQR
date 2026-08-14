<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Una tarea del plan de implementacion: `### Tarea 1.13 — Provision, entrega y
 * restablecimiento del PIN`, dentro de un fichero que declara su fase.
 */
final readonly class PlanTask
{
    public function __construct(
        public string $number,
        public int $phase,
        public string $title,
        public string $file,
    ) {}

    public function describe(): string
    {
        return $this->number.' ('.$this->file.')';
    }
}
