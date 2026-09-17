<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;

/**
 * La vista de cumplimiento completa (**RF-PA-06**): los hallazgos del periodo y
 * el criterio con el que se han medido.
 *
 * ## Es el objeto sobre el que se declara la policy
 *
 * `ComplianceSummaryPolicy` se registra contra esta clase y no contra un modelo
 * Eloquent, por lo mismo que `PresenceBoard` y `WorkDayJournal`: asi la
 * autorizacion se decide **antes** de tocar la base de datos. Declarada sobre una
 * fila, habria que cargarla para poder preguntar si se puede leer, que es la via
 * por la que la autorizacion acaba ocurriendo despues del acceso a los datos.
 *
 * ## Los criterios viajan como CLAVES, no como texto
 *
 * El dominio no tiene idioma (mismo criterio que {@see PeriodReport}). Las claves
 * de `criteria` las traduce el `Resource` con el idioma de la peticion, y por eso
 * la misma consulta sirve a una pantalla en español y a otra en ingles sin
 * duplicar nada.
 */
final readonly class ComplianceSummary
{
    /**
     * @param  list<ComplianceFinding>  $findings  solo incumplimientos, ya ordenados
     * @param  list<ComplianceRuleStatus>  $rules  las cuatro, en orden RN-10, RN-11, RN-12, RN-17
     * @param  list<string>  $criteria  claves de `lang/*\/compliance-summary.php`
     */
    public function __construct(
        public array $findings,
        public DateTimeImmutable $generatedAt,
        /** Zona del centro (ADR-040). Las jornadas y semanas ya estan expresadas en ella. */
        public string $timeZone,
        public DateRange $range,
        public ComplianceProfileRef $profile,
        /** Dia ISO-8601 en que empieza la semana del perfil. */
        public int $weekStartsOn,
        public array $rules,
        public ComplianceTotals $totals,
        /** `true` cuando quien pregunta alcanza a toda la plantilla. */
        public bool $unrestrictedScope,
        public array $criteria,
    ) {}

    public function findingCount(): int
    {
        return \count($this->findings);
    }

    /** `all` o `departments`, el vocabulario del contrato para `meta.scope`. */
    public function scopeName(): string
    {
        return $this->unrestrictedScope ? 'all' : 'departments';
    }
}
