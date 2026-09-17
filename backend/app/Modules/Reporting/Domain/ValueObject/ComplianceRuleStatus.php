<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Una regla del perfil **tal como se ha aplicado en esta respuesta**: su umbral y
 * si se ha evaluado.
 *
 * Las cuatro viajan siempre, se filtre por una o no, porque el criterio es parte
 * de la vista y no de la documentacion (RF-PA-06, paso 5 de la ficha): un aviso
 * cuyo criterio no se ve es un aviso que nadie defiende ante un empleado — y una
 * regla **ausente** es peor todavia, porque quien no ve alertas de pausas cree
 * que nadie encadena seis horas.
 */
final readonly class ComplianceRuleStatus
{
    public function __construct(
        public ComplianceRuleName $rule,
        public int $thresholdMinutes,
        /** `false` cuando la regla esta suspendida: el umbral se enseña y no se aplica. */
        public bool $evaluated,
        public ?ComplianceSuspensionReason $suspensionReason = null,
    ) {}

    public function requirement(): string
    {
        return $this->rule->requirement();
    }
}
