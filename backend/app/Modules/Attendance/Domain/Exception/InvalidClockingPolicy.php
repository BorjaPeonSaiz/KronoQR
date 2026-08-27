<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Los umbrales de RN-07 y RN-08 llegan de dos fuentes de configuracion
 * distintas y nadie los compara entre si antes de aplicarlos.
 *
 * Una politica con la duracion minima computable por encima de la anomala
 * clasificaria todo tramo como corto **y** largo a la vez. Es un estado
 * imposible, asi que se rechaza al construir la politica y no al evaluarla: el
 * fallo aparece al arrancar con una configuracion mala, no meses despues en el
 * informe de una persona.
 */
final class InvalidClockingPolicy extends AttendanceDomainException
{
    public static function minimumAboveAnomalous(int $minimumMinutes, int $anomalousMinutes): self
    {
        return new self(sprintf(
            'The minimum computable shift (%d min, RN-07) cannot be longer than the anomalous threshold (%d min, RN-08).',
            $minimumMinutes,
            $anomalousMinutes,
        ));
    }
}
