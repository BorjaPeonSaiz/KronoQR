<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Una persona del padron minimo del quiosco (doc 02 §7.3, RF-KI-03).
 *
 * **Dos campos, y son los dos que el §7.3 autoriza a salir por `roster:read`**:
 * el identificador interno con el que otro modulo puede cruzar sus tablas, y el
 * nombre en su forma minima —nombre de pila e inicial del primer apellido—.
 *
 * Ni UUID, ni codigo de empleado, ni departamento, ni situacion laboral, ni
 * fechas. Cada campo que se añada aqui acaba en una tablet colgada de una pared y
 * se filtra entero el dia que alguien se la lleve (RS-04). Si algun dia hace
 * falta uno mas, la pregunta no es como añadirlo sino por que el quiosco no puede
 * resolverlo sin el.
 *
 * **Vive en `Shared` porque cruza tres modulos.** Lo produce `Workforce` —que
 * tiene los nombres—, lo consume `Kiosk` —que sirve el padron— y lo cruza
 * `Identity` —que tiene los hashes—. Devolver aqui un modelo Eloquent o una
 * entidad de `Workforce` acoplaria los tres por el tipo de retorno, que es la
 * forma en que la restriccion 2 de ADR-025 se erosiona sin que ningun `use` lo
 * delate.
 *
 * **`employeeId` es la clave interna y no sale de la aplicacion.** El padron que
 * viaja al quiosco no la lleva: se usa para cruzar con `credentials.employee_id`
 * y se queda en el servidor. Un identificador secuencial en una respuesta diria
 * cuanta gente hay y en que orden entro.
 */
final readonly class RosterMember
{
    public function __construct(
        public int $employeeId,
        /** Nombre de pila e inicial del primer apellido. **Jamas en un log** (regla dura 21). */
        public string $displayName,
    ) {
        if ($employeeId < 1) {
            throw new InvalidArgumentException('RosterMember necesita la clave interna del empleado.');
        }

        if ($displayName === '') {
            throw new InvalidArgumentException('RosterMember necesita un nombre para mostrar.');
        }
    }
}
