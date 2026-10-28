<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use InvalidArgumentException;

/**
 * **En que punto esta un PIN rechazado** (RN-19, doc 01 §4), y con el, la
 * jornada de esa persona.
 *
 * Un intento esta en uno de tres estados, y los decide
 * {@see PinAttemptRecoveryPolicy::statusOf()}:
 *
 * - `RECOVERED`: la misma persona tiene un fichaje que lo subsana en su
 *   ventana. Es definitivo: un fichaje aceptado no se deshace.
 * - `PENDING`: no hay subsanacion **todavia** y la ventana de 600 s sigue
 *   abierta. La revision no lo juzga: lo vera en la pasada siguiente
 *   (doc 01 §4, RN-19).
 * - `UNRECOVERED`: la ventana se cerro sin subsanacion. Es lo unico que abre
 *   incidencia.
 *
 * La incidencia es **de la jornada**, asi que tambien su estado:
 * {@see self::ofWorkDay()}.
 */
enum RejectedPinStatus: string
{
    case RECOVERED = 'recovered';
    case PENDING = 'pending';
    case UNRECOVERED = 'unrecovered';

    /**
     * El estado de la jornada a partir del de sus intentos.
     *
     * - Basta **un** intento sin subsanar para que la jornada lo este: la
     *   incidencia dice «esta persona tiene un fichaje que no consta», y uno
     *   basta.
     * - Si ninguno lo esta pero alguno espera su ventana, la jornada espera.
     * - Solo con **todos** subsanados la jornada lo esta, y es lo que cierra una
     *   incidencia que se abrio antes de que la subsanacion llegara.
     *
     * @param  list<self>  $attempts
     */
    public static function ofWorkDay(array $attempts): self
    {
        if ($attempts === []) {
            throw new InvalidArgumentException('Una jornada sin intentos por PIN rechazados no tiene estado de RN-19.');
        }

        $pending = false;

        foreach ($attempts as $attempt) {
            if ($attempt === self::UNRECOVERED) {
                return self::UNRECOVERED;
            }

            $pending = $pending || $attempt === self::PENDING;
        }

        return $pending ? self::PENDING : self::RECOVERED;
    }
}
