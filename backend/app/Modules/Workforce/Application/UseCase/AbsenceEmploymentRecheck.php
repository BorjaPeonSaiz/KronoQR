<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\Model\Absence;

/**
 * **El periodo de empleo de una ausencia, vuelto a mirar con la cadena de
 * `audit_log` ya tomada** (RN-14, ADR-046; revision del bloque 17).
 *
 * El registro, la correccion y la importacion de ausencias leen la ficha sin
 * candado y antes de su asiento. Una baja que confirmara entre esa lectura y la
 * escritura, con un cese anterior a la ausencia, la dejaria fuera del periodo de
 * empleo. No se toma la cadena al principio —la insercion comprueba su clave
 * ajena sobre la ficha y bloquea antes las ausencias de la persona—: se llama a
 * esto **despues del asiento**, cuando la cadena ya es de esta transaccion
 * (`withChainLock()` es reentrante y no espera). Con ella tomada, la baja o ya
 * confirmo —y esta lectura la ve— o confirmara despues de la ausencia. Si ya no
 * cabe, la excepcion deshace la transaccion entera, asiento incluido.
 *
 * Una sola clase para los tres casos de uso: es la misma regla, y tres copias
 * acabarian separandose.
 */
final readonly class AbsenceEmploymentRecheck
{
    public function __construct(
        private EmployeeRepository $employees,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * @throws InvalidAbsencePeriod si la ausencia ya no cae en el periodo de empleo
     */
    public function assertStillWithinEmployment(Absence $absence): void
    {
        $this->serialized->withChainLock(function () use ($absence): void {
            $current = $this->employees->findByUuid($absence->employeeUuid);

            if ($current === null || ! $absence->fallsWithinEmployment($current->hiredAt, $current->terminatedAt)) {
                throw InvalidAbsencePeriod::isOutsideEmployment($absence->isoStartsOn(), $absence->isoEndsOn());
            }
        });
    }
}
