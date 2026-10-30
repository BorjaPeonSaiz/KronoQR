<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Alguna persona de una readmision en lote no se puede readmitir (RN-27,
 * ADR-062 §6): **no se readmite ninguna**. Lleva un error por posicion de la
 * lista, con la clave y los parametros de su texto, y se traduce a `422` con
 * `employee_uuids.N`.
 *
 * `getMessage()` lleva solo las posiciones (regla dura 21).
 */
final class RehireBatchRejected extends WorkforceDomainException implements BusinessRejection
{
    public const string NOT_FOUND = 'employees.errors.rehire_not_found';

    /**
     * @param  array<int, array{key: string, parameters: array<string, string>, message: string}>  $errors  por posicion
     */
    private function __construct(public readonly array $errors)
    {
        parent::__construct(sprintf('The batch rehire was rejected at positions %s (RN-27).', implode(', ', array_keys($errors))));
    }

    /**
     * @param  array<int, array{key: string, parameters: array<string, string>, message: string}>  $errors
     */
    public static function at(array $errors): self
    {
        return new self($errors);
    }
}
