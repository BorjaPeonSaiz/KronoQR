<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Readmision de una persona de baja (RN-27, ADR-062 §6).
 *
 * `hiredAt` es la fecha civil de alta del periodo nuevo (`Y-m-d`). Lo demas se
 * toma de la ficha salvo que se de:
 *
 * - `departmentId` con `departmentGiven` (con `null`, sin departamento) y
 *   `locale`;
 * - `contract`: con `contractGiven` y un valor, esos terminos; con
 *   `contractGiven` y `null`, ningun contrato; sin `contractGiven`, los del
 *   ultimo contrato si hubo alguno;
 * - `resetPin`: un PIN nuevo en lugar de conservar el que tenia.
 *
 * `viaImport` marca la readmision que llega por la importacion, para el asiento,
 * y `countedByBatch` la que cuenta en el plan su lote y no ella.
 */
final readonly class RehireEmployeeCommand
{
    public function __construct(
        public string $uuid,
        public string $hiredAt,
        public ?int $actorUserId,
        public ?int $departmentId = null,
        public bool $departmentGiven = false,
        public ?string $locale = null,
        public ?RehireContractTerms $contract = null,
        public bool $contractGiven = false,
        public bool $resetPin = false,
        public bool $viaImport = false,
        public bool $countedByBatch = false,
    ) {}
}
