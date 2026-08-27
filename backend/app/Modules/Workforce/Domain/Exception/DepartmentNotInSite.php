<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El departamento indicado no pertenece al centro del empleado.
 *
 * **No es un capricho de integridad referencial.** Un empleado adscrito a un
 * departamento de otro hotel rompe los informes por centro y, peor, deja
 * ambiguo de que centro sale su zona horaria, que es de lo que depende a que
 * jornada se atribuye cada tramo (RN-05).
 *
 * Se responde como error de validacion (`422`) porque el cliente puede
 * corregirlo eligiendo bien.
 */
final class DepartmentNotInSite extends WorkforceDomainException
{
    public static function make(int $departmentId, int $siteId): self
    {
        return new self('El departamento '.$departmentId.' no pertenece al centro '.$siteId.'.');
    }
}
