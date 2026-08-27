<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * El motivo de una correccion no llega a existir (doc 01 Anexo C, RF-PA-04).
 *
 * Es una excepcion de construccion, no de validacion posterior: quien la ve es
 * quien intento fabricar un `CorrectionReason` invalido, y nadie que reciba uno
 * tiene que volver a comprobarlo. El `FormRequest` de la tarea 1.15 rechaza
 * antes con un 422 legible; esto es la red que impide que un caso de uso, una
 * orden de consola o una importacion escriban en el registro legal una
 * correccion sin motivo utilizable.
 */
final class InvalidCorrectionReason extends AttendanceDomainException
{
    public static function unknownCode(string $code): self
    {
        return new self('"'.$code.'" is not a correction reason of the Annex C catalogue.');
    }

    public static function needsExplanation(string $code, int $length, int $minimum): self
    {
        return new self(
            'Correction reason '.$code.' requires a written explanation of at least '
            .$minimum.' characters; got '.$length.'.'
        );
    }
}
