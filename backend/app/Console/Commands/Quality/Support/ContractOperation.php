<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Una operacion de `paths:` del contrato y los requisitos que cita su resumen o
 * su descripcion.
 */
final readonly class ContractOperation
{
    /**
     * @param  list<string>  $citations
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $operationId,
        public array $citations,
    ) {}

    public function describe(): string
    {
        return $this->operationId.' ('.$this->method.' '.$this->path.')';
    }
}
