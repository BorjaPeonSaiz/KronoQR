<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Exception;

use RuntimeException;

/**
 * No hay ningun empleado con ese identificador publico.
 *
 * Es un `404` y no una respuesta vacia, y la diferencia importa: «esta persona
 * no existe» y «esta persona no trabajo esos dias» son dos hechos distintos, y
 * un panel que los confunda enseñaria una jornada en blanco a quien escribio mal
 * el identificador.
 *
 * **No hay riesgo de enumeracion aqui.** La regla dura 17 —rechazos genericos e
 * indistinguibles— protege el camino de FICHAJE, donde quien escanea es
 * cualquiera delante de una pantalla en un pasillo. Este endpoint exige una
 * cuenta de gestion con rol `manager+`, que ya puede listar la plantilla entera
 * por `GET /employees`: ocultarle si un UUID existe no protegeria nada y le
 * costaria un diagnostico.
 */
final class EmployeeNotFound extends RuntimeException
{
    public static function withUuid(string $employeeUuid): self
    {
        return new self('No existe ningun empleado con el identificador '.$employeeUuid.'.');
    }
}
