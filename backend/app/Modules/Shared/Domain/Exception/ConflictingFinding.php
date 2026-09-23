<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use RuntimeException;

/**
 * Un hallazgo **distinto** ha chocado con otro ya escrito sobre la misma
 * cuadrupla de `one_incident_per_finding` (decision 13d de la ficha 3.11).
 *
 * ## Que problema existe para resolver
 *
 * `incidents` deduplica por `(employee_id, work_date, type, shift_entry_id)` con
 * `NULLS NOT DISTINCT`, y eso es exactamente lo que hace idempotente repetir la
 * deteccion: el mismo hallazgo dos veces no escribe dos filas. Pero con
 * `anomalous_pattern` la cuadrupla dejo de identificar al hallazgo: dos indicios
 * distintos de la misma persona y el mismo dia —una coincidencia sistematica y
 * una secuencia imposible, o dos contrapartes distintas— caen en la misma
 * cuadrupla con `shift_entry_id` nulo. El `ON CONFLICT DO NOTHING` los tragaba
 * **sin fila, sin asiento, sin fallo y sin log**: el segundo indicio
 * desaparecia y nadie podia saberlo.
 *
 * Esta excepcion es lo que lo convierte en visible. **No repara nada** —seguir
 * habiendo una sola incidencia es correcto: la bandeja no puede tener dos filas
 * ahi— pero lo cuenta como fallo de la pasada, deja una linea en el log con
 * identificadores y hace que `pattern_detection_last_failures` suba, que es lo
 * que `DeteccionDePatronesConFallos` vigila.
 *
 * ## Por que vive en `Shared`
 *
 * La lanza `Compliance` —que es quien tiene la tabla— y la atrapa `Attendance`
 * —que es quien publica los hallazgos y cuenta los fallos de su pasada—. Ninguno
 * de los dos puede importar el dominio del otro (doc 02 §1.6, Deptrac), y un
 * `Throwable` generico obligaria a distinguirlo por el mensaje. Cumple el
 * criterio de admision de ADR-021: lo necesitan varios modulos, no es una regla
 * de negocio de ninguno y no depende de nada.
 *
 * **Sin datos personales en el mensaje** (regla dura 21): identificadores,
 * fecha y tipo. El mensaje acaba en el log tecnico y de ahi en el paquete de
 * diagnostico (ADR-020).
 */
final class ConflictingFinding extends RuntimeException
{
    private function __construct(
        string $message,
        /** Identificador publico de la persona a la que apunta el hallazgo que no se pudo escribir. */
        public readonly string $employeeUuid,
        /** Jornada del hallazgo, en `Y-m-d`. */
        public readonly string $workDate,
    ) {
        parent::__construct($message);
    }

    public static function onTheSameFinding(string $employeeUuid, string $workDate, string $type): self
    {
        return new self(
            'Otro hallazgo de tipo '.$type.' ya ocupa la jornada '.$workDate.' de '.$employeeUuid.': '
            .'la incidencia existente describe un indicio distinto y no se sobrescribe.',
            $employeeUuid,
            $workDate,
        );
    }
}
