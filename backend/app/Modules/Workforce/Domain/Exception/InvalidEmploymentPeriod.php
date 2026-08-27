<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * La fecha de cese no es coherente con la de alta.
 *
 * La misma regla esta declarada en la base de datos
 * (`employees_chk_terminated_after_hired`, tarea 1.3) y aqui. No es
 * duplicacion inutil: la de la base de datos protege de las escrituras que no
 * pasan por el dominio —una importacion, una correccion manual—, y esta da un
 * error con significado a quien esta dando la baja.
 */
final class InvalidEmploymentPeriod extends WorkforceDomainException
{
    public static function terminationBeforeHiring(string $terminatedAt, string $hiredAt): self
    {
        return new self('La fecha de cese ('.$terminatedAt.') es anterior a la de alta ('.$hiredAt.').');
    }
}
