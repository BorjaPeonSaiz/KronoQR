<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * La ficha esta minimizada por retencion (ADR-060) y su vida laboral ya no
 * cambia: ni se readmite ni se corrige su fecha de cese (RN-27, ADR-062 §6).
 *
 * Quien vuelve despues de la minimizacion es un alta nueva: la ficha ya no
 * conserva el documento con el que reconocerla. Distinto de
 * {@see EmployeeRecordMinimised}, que es el `409` generico de los contratos y las
 * ausencias: este tiene su propio `type` en el contrato
 * (`employee-record-minimised`).
 */
final class MinimisedEmploymentHistory extends WorkforceDomainException implements BusinessRejection
{
    public const string TRANSLATION_KEY = 'employees.errors.employment_history_minimised';

    /**
     * @param  array<string, string>  $parameters
     */
    private function __construct(
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function withUuid(string $uuid, string $anonymizedOn): self
    {
        return new self(
            ['anonymized_on' => $anonymizedOn],
            sprintf('The personnel record %s was minimised on %s (ADR-060): it is not rehired nor corrected (RN-27).', $uuid, $anonymizedOn),
        );
    }
}
