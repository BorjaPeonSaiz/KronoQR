<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

/**
 * El desenlace de corregir una jornada sospechosa, con lo que haga falta
 * informar de el (RF-PR-02).
 *
 * `outcome` decide **como se cuenta** ({@see CorrectionOutcome}) y `divergence`
 * es lo que se escribio o se intento escribir — `null` cuando no habia nada que
 * escribir, que es el caso de la sospecha que se deshizo sola.
 *
 * No es solo un `enum` porque los desenlaces que cuentan como divergencia
 * necesitan viajar con las columnas afectadas: el informe las agrupa por
 * columna, y las de la divergencia confirmada no tienen por que ser las que vio
 * la inspeccion.
 */
final readonly class CorrectionAttempt
{
    private function __construct(
        public CorrectionOutcome $outcome,
        /** Lo que se corrigio o se intento corregir. `null` si no habia nada. */
        public ?DailyTotalsDivergence $divergence,
    ) {}

    public static function corrected(DailyTotalsDivergence $confirmed): self
    {
        return new self(CorrectionOutcome::Corrected, $confirmed);
    }

    public static function resolvedItself(): self
    {
        return new self(CorrectionOutcome::ResolvedItself, null);
    }

    public static function failed(DailyTotalsDivergence $detected): self
    {
        return new self(CorrectionOutcome::Failed, $detected);
    }

    /**
     * No se pudo ni mirar: el candado no llego o PostgreSQL rompio un abrazo
     * mortal. Lleva la sospecha para poder informar de que jornada se quedo sin
     * revisar, y **no** cuenta como divergencia.
     */
    public static function contended(DailyTotalsDivergence $detected): self
    {
        return new self(CorrectionOutcome::Contended, $detected);
    }

    /**
     * Las columnas que van al informe, vacio cuando este desenlace no habla de
     * la integridad de la proyeccion.
     *
     * @return list<string>
     */
    public function divergentFields(): array
    {
        return $this->outcome->countsAsDivergence() && $this->divergence instanceof DailyTotalsDivergence
            ? $this->divergence->fields
            : [];
    }
}
