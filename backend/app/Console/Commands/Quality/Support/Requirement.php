<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Una fila de docs/requisitos.yaml: un requisito, ya expandido, con la fase en
 * la que lo construye el plan y su enunciado del doc 01.
 */
final readonly class Requirement
{
    public function __construct(
        public string $id,
        public int $phase,
        public string $title,
    ) {}
}
