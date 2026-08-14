<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Una prueba que declara que requisitos cubre (§9.6).
 */
final readonly class TaggedTest
{
    /**
     * @param  string  $tool  `pest` o `playwright`.
     * @param  list<string>  $requirements  Identificadores citados en la etiqueta.
     */
    public function __construct(
        public string $tool,
        public string $file,
        public int $line,
        public string $name,
        public array $requirements,
    ) {}

    public function reference(): string
    {
        return $this->file.':'.$this->line;
    }
}
