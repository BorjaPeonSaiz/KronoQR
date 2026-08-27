<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Exception;

use RuntimeException;

/**
 * No se puede escribir tiempo trabajado a nombre de esta persona: no existe, no
 * esta activa (RN-14) o su centro no tiene zona horaria configurada.
 *
 * Solo la produce el alta manual. En el quiosco, las tres situaciones recorren
 * el camino generico de rechazo, indistinguible desde fuera (RS-03, regla dura
 * 17); aqui quien pregunta es un responsable autenticado desde el panel y
 * ocultarselo solo conseguiria que abriera una incidencia de soporte.
 *
 * **Nunca lleva el nombre del empleado** (regla dura 21): identifica por uuid,
 * porque este mensaje acaba en el log tecnico.
 */
final class EmployeeCannotBeClocked extends RuntimeException
{
    public function __construct(public readonly string $employeeUuid)
    {
        parent::__construct('Employee '.$employeeUuid.' cannot have shift entries recorded (unknown, inactive, or site without timezone).');
    }

    public static function withUuid(string $employeeUuid): self
    {
        return new self($employeeUuid);
    }
}
