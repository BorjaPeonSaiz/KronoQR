<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Todo lo que hace falta para contarle a alguien como esta su licencia: estado,
 * plan, vigencia, uso frente a contratado y que esta degradado (RF-PD-04,
 * RF-PD-05, ADR-028).
 *
 * Existe para que las tres superficies que responden a esa pregunta —el panel,
 * `GET /api/v1/license` y `license:show`— salgan del **mismo calculo**. Sin este
 * objeto cada una compondria su version, y la primera vez que difirieran seria
 * en una revision comercial con el cliente delante.
 */
final readonly class LicenseOverview
{
    /**
     * @param  list<PlanUsage>  $usage  Una entrada por cada {@see PlanLimit}, siempre las dos.
     */
    public function __construct(
        public LicenseStatus $status,
        public ?StoredLicense $stored,
        public array $usage,
    ) {}

    /**
     * Las magnitudes que hoy estan por encima del plan.
     *
     * @return list<PlanUsage>
     */
    public function exceeded(): array
    {
        return array_values(array_filter(
            $this->usage,
            static fn (PlanUsage $usage): bool => $usage->isExceeded(),
        ));
    }

    /**
     * Si hay algo que enseñar en el banner persistente del panel: o la licencia
     * no esta en su estado normal, o alguna cifra se ha superado.
     *
     * Los dos avisos son persistentes y por el mismo motivo (ADR-028): se dejan
     * de ver cuando el hecho deja de ser cierto, no cuando alguien los cierra.
     * Un aviso descartable sobre una licencia caducada se descarta el primer dia
     * y ya nadie se entera de nada.
     */
    public function needsNotice(): bool
    {
        return $this->status->state->needsNotice() || $this->exceeded() !== [];
    }

    /**
     * El tono del aviso: manda el mas grave de los dos ejes.
     *
     * Un exceso de plan es `Warning` y nunca `Critical`: el cliente esta al
     * corriente de pago y ha crecido, que es una conversacion comercial, no una
     * averia. ADR-028 lo dice con todas las letras al descartar la alternativa
     * de degradar funcionalidades por exceso.
     */
    public function severity(): LicenseNoticeSeverity
    {
        $fromState = $this->status->state->severity();

        if ($fromState === LicenseNoticeSeverity::Critical) {
            return LicenseNoticeSeverity::Critical;
        }

        if ($this->exceeded() !== []) {
            return LicenseNoticeSeverity::Warning;
        }

        return $fromState;
    }
}
