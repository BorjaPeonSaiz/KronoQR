<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * El periodo de una ausencia no describe ningun intervalo (**RF-GP-04**).
 *
 * La misma regla esta declarada en `absences_chk_period` y en el `FormRequest`.
 * No es duplicacion inutil: el `CHECK` protege de lo que no pasa por la API —una
 * importacion, un `psql`—, el `FormRequest` da un `422` con el campo señalado y
 * esta da un mensaje con significado a cualquier otro camino.
 *
 * **Sin el tipo ni la nota en el mensaje** (regla dura 21): una excepcion acaba
 * en un log tecnico y en `error_events`, que viaja al fabricante dentro del
 * paquete de diagnostico. Aqui solo van fechas y, cuando hace falta, el `uuid`.
 */
final class InvalidAbsencePeriod extends WorkforceDomainException implements BusinessRejection
{
    public static function isInverted(string $startsOn, string $endsOn): self
    {
        return new self('La ausencia termina ('.$endsOn.') antes de empezar ('.$startsOn.').');
    }

    /**
     * La ausencia no cae entera en ningun periodo de alta de la persona
     * (RF-GP-04, RN-27).
     *
     * Empieza antes de un alta, termina despues de un cese o pisa el hueco entre
     * una baja y una readmision: no son dias que esa persona fuera a trabajar,
     * asi que registrarlos no justifica nada y ademas mentiria en el informe.
     * Hasta la 2.2.x se admitia el solape parcial (decision 2 de la ficha 3.10);
     * con varios periodos, ese criterio dejaba pasar una ausencia que salta el
     * hueco.
     */
    public static function isOutsideEmployment(string $startsOn, string $endsOn): self
    {
        return new self(
            'La ausencia ('.$startsOn.' a '.$endsOn.') no cae dentro de ningun periodo de alta de la persona.',
        );
    }
}
