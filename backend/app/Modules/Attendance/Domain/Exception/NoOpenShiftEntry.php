<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * No hay tramo que cerrar en esta jornada.
 *
 * En el recorrido normal no ocurre: el caso de uso decide si el escaneo abre o
 * cierra mirando si hay jornada con tramo abierto (RF-AT-02, RF-AT-03), asi que
 * llegar aqui significa que el estado cambio entre la lectura y la escritura.
 * Es la senal de una carrera, y por eso se lanza en vez de convertirse en una
 * entrada silenciosa.
 */
final class NoOpenShiftEntry extends AttendanceDomainException
{
    public static function forEmployeeOn(string $employeeUuid, string $workDate): self
    {
        return new self('Employee '.$employeeUuid.' has no open shift entry on work day '.$workDate.'.');
    }
}
