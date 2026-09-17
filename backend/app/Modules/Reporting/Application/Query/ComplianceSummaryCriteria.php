<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleName;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que pide quien abre la vista de cumplimiento (**RF-PA-06**), tal como vino
 * en la URL.
 *
 * Las dos fechas son **opcionales y llegan sin interpretar**, igual que en
 * {@see EmployeeWorkDayRange} y por el mismo motivo: resolver la omision necesita
 * saber que dia es hoy **en la zona del centro** (ADR-040), y eso solo se sabe
 * despues de buscarlo. Un `FormRequest` que pusiera el valor por omision lo
 * pondria con la zona del servidor, y a las 00:30 de Madrid eso deja fuera la
 * jornada en curso justo en el turno de noche.
 *
 * **El alcance va dentro y no al lado.** Es lo que quien llama no puede elegir
 * (RF-ID-03): lo resuelve la capa HTTP a partir del token, y viaja con los
 * filtros para que no exista ninguna ruta por la que se pueda consultar sin el.
 */
final readonly class ComplianceSummaryCriteria
{
    public function __construct(
        public AccessScope $scope,
        /** `YYYY-MM-DD` o `null` para «los 28 dias que terminan en `to`». */
        public ?string $from = null,
        /** `YYYY-MM-DD` o `null` para «hoy en la zona del centro». */
        public ?string $to = null,
        public ?int $departmentId = null,
        public ?string $employeeUuid = null,
        public ?ComplianceRuleName $rule = null,
    ) {}
}
