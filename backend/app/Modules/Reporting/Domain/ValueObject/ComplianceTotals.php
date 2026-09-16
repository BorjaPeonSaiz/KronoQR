<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Recuentos del alcance y del periodo pedidos (RF-PA-06).
 *
 * **Acotados por el alcance igual que `data`** (RF-ID-03). Es la mitad que se
 * olvida: un `employees_affected` que contara a gente de otro departamento seria
 * una fuga aunque la lista estuviera bien filtrada, porque diria cuanta gente
 * incumple en un sitio que quien pregunta no puede ver.
 *
 * **Los recuentos describen el PERIODO, no la lista filtrada.** Se calculan sobre
 * todos los hallazgos, antes de aplicar el filtro `rule`: las cuatro tarjetas del
 * panel son la foto completa del periodo y no pueden cambiar al pulsar una de
 * ellas. Calcularlos sobre `data` haria que filtrar por «descansos» pusiera a cero
 * las otras tres, y quien lo hiciera creeria que han dejado de existir. Lo que el
 * filtro acota es `data`; lo que no toca son ni los recuentos ni el **criterio**
 * —`meta.rules[]`, con las cuatro reglas y sus umbrales—.
 *
 * **`byRule` lleva las cuatro claves siempre, tambien con cero**: una clave que
 * desapareciera obligaria al panel a inventarse el cero.
 *
 * **`employeesEvaluated` es el denominador honesto**: personas del alcance con
 * alguna jornada **evaluada**. Quien no ficho no ha cumplido ni incumplido, y
 * meterlo en el denominador diria que el hotel cumple mejor cuantas mas bajas
 * tiene. Se mide sobre la misma ventana que se evalua —semanas del borde
 * incluidas— para que nunca pueda quedar por debajo de `employeesAffected`: ver
 * `ReadComplianceSummary::employeesEvaluated()`.
 */
final readonly class ComplianceTotals
{
    /**
     * @param  array<string, int>  $byRule  recuento por valor de {@see ComplianceRuleName}
     */
    public function __construct(
        public array $byRule,
        public int $employeesAffected,
        public int $employeesEvaluated,
    ) {}

    /**
     * @param  list<ComplianceFinding>  $findings  TODOS los del periodo, antes del filtro por regla
     */
    public static function of(array $findings, int $employeesEvaluated): self
    {
        /** @var array<string, int> $byRule */
        $byRule = [];

        foreach (ComplianceRuleName::inRequirementOrder() as $rule) {
            $byRule[$rule->value] = 0;
        }

        $affected = [];

        foreach ($findings as $finding) {
            $byRule[$finding->rule->value] = ($byRule[$finding->rule->value] ?? 0) + 1;
            $affected[$finding->employee->uuid] = true;
        }

        return new self($byRule, \count($affected), $employeesEvaluated);
    }
}
