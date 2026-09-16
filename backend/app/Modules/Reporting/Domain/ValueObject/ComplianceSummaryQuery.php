<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que se le pide a la vista de cumplimiento (**RF-PA-06**).
 *
 * **El alcance va dentro de la consulta y no al lado.** Es lo que quien llama no
 * puede elegir (RF-ID-03): lo resuelve la capa HTTP a partir del token y viaja
 * junto a los filtros para que no exista ninguna ruta por la que se pueda
 * consultar sin el.
 *
 * **`departmentId` y `employeeUuid` son filtros, no autorizaciones.** Fuera del
 * alcance no producen `403` sino un resultado vacio, igual que en la presencia en
 * vivo y en `GET /employees`: un `403` al filtrar convertiria el desplegable del
 * panel en un generador de errores y ademas confirmaria que ese departamento o
 * esa persona existen.
 *
 * **`rule` acota `data` y nada mas.** Los umbrales de las cuatro reglas siguen
 * viajando en `meta.rules[]`: el filtro elige que se lista, no con que criterio
 * se ha medido.
 */
final readonly class ComplianceSummaryQuery
{
    public function __construct(
        public AccessScope $scope,
        public DateRange $range,
        public ?int $departmentId,
        public ?string $employeeUuid,
        public ?ComplianceRuleName $rule,
    ) {}
}
