<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use DateTimeImmutable;

/**
 * Un periodo de alta tal como lo ve la ficha de `rrhh+` (RN-27, ADR-062 §1 y
 * §7; esquema `EmploymentPeriodRecord`): el objeto de valor, el instante en que
 * su baja se hizo efectiva y el historico de las correcciones de su fecha de
 * cese.
 *
 * **Por que no son dos campos mas de {@see EmploymentPeriod}.** El objeto de
 * valor es del dominio y lo leen `Attendance` y la exportacion legal para
 * decidir; ni el instante de la baja ni el historico cambian ninguna decision.
 * Viajan aqui, en la lectura, como el responsable en {@see DepartmentView}.
 */
final readonly class EmploymentPeriodRecord
{
    /**
     * @param  list<TerminationCorrectionRecord>  $corrections  de la mas antigua a la mas reciente
     */
    public function __construct(
        public EmploymentPeriod $period,
        public ?DateTimeImmutable $offboardedAt,
        public array $corrections = [],
    ) {}
}
