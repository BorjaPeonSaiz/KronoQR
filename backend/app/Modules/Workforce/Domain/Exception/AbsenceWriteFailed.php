<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use Throwable;

/**
 * Una escritura sobre `absences` ha fallado en el motor por algo que no es un
 * caso de negocio (**RF-GP-04**, regla dura 21).
 *
 * ## Por que existe: el mensaje de una `QueryException` lleva los bindings
 *
 * Laravel compone el mensaje de una `QueryException` con la sentencia **y los
 * valores enlazados**. En esta tabla esos valores son la nota de una ausencia y
 * su tipo, es decir texto libre que puede contener un diagnostico y una
 * categoria que puede ser `sick_leave`: **dato relativo a la salud** del art. 9
 * del RGPD.
 *
 * Ese mensaje no se queda en la respuesta —el manejador devuelve un `500`
 * generico— pero **si se escribe en `storage/logs`**, que es donde va a parar
 * cualquier excepcion no controlada. `error_events` esta saneado; el log de
 * fichero no. Asi que la excepcion se sustituye **en el borde del repositorio**,
 * antes de que nadie la registre.
 *
 * ## Que lleva y que no
 *
 * El `uuid` publico de la ausencia, el nombre de la operacion y el `SQLSTATE`.
 * Con esos tres se diagnostica: el `SQLSTATE` dice que clase de restriccion se
 * violo y el `uuid` dice sobre que fila. **La original viaja como `previous`**,
 * que es lo correcto para una traza de depuracion en desarrollo y lo que el
 * manejador de errores no vuelca al log de produccion.
 *
 * ## No es un conflicto
 *
 * No hereda de {@see WorkforceConflict} a proposito: si esto se lanza, hay un
 * defecto del producto y la respuesta honesta es un `500` con su entrada en el
 * historico de errores, no un `409` que invite a reintentar algo que volvera a
 * fallar igual.
 */
final class AbsenceWriteFailed extends WorkforceDomainException
{
    public static function during(string $operation, string $absenceUuid, string $sqlState, Throwable $cause): self
    {
        return new self(
            'La escritura de la ausencia '.$absenceUuid.' ha fallado durante «'.$operation
            .'» con SQLSTATE '.$sqlState.'.',
            0,
            $cause,
        );
    }
}
