<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Readmision en lote con una fecha de alta comun (RN-27, ADR-062 §6): todo o
 * nada. Cada persona conserva su departamento, su idioma y los terminos de su
 * ultimo contrato.
 */
final readonly class RehireEmployeesCommand
{
    /**
     * @param  list<string>  $uuids
     */
    public function __construct(
        public array $uuids,
        public string $hiredAt,
        public ?int $actorUserId,
        public bool $resetPin = false,
    ) {}
}
