<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

/**
 * El desenlace de una pasada de reconciliacion (RF-PR-02).
 *
 * Son recuentos, nunca personas: lo que sale por pantalla y al log es «cuantas
 * jornadas se revisaron y cuantas no cuadraban», y el detalle de cual va al log
 * con `employee_uuid` (regla dura 21).
 *
 * `divergences` **tiene que ser cero siempre** (doc 02 §8.2). Que no lo sea no
 * es una metrica de tendencia: es un incidente de integridad, y por eso el
 * comando termina con codigo distinto de cero aunque haya corregido todo.
 */
final readonly class ReconciliationReport
{
    /**
     * @param  array<string, int>  $byField  divergencias por columna de `daily_totals`
     */
    private function __construct(
        public bool $ranOverASite,
        public string $fromIsoDate,
        public string $toIsoDate,
        public int $daysInspected,
        public int $workDaysInspected,
        public int $divergences,
        public int $corrected,
        public int $failures,
        public array $byField,
    ) {}

    /**
     * Antes de la puesta en marcha no hay centro y por tanto no hay zona horaria
     * con la que decir a que jornada pertenece un turno (RF-PD-03, RN-05). No es
     * un fallo: no hay nada que reconciliar.
     */
    public static function withoutSite(): self
    {
        return new self(false, '', '', 0, 0, 0, 0, 0, []);
    }

    /**
     * @param  array<string, int>  $byField
     */
    public static function of(
        string $fromIsoDate,
        string $toIsoDate,
        int $daysInspected,
        int $workDaysInspected,
        int $divergences,
        int $corrected,
        int $failures,
        array $byField,
    ): self {
        return new self(
            true,
            $fromIsoDate,
            $toIsoDate,
            $daysInspected,
            $workDaysInspected,
            $divergences,
            $corrected,
            $failures,
            $byField,
        );
    }

    /**
     * La proyeccion coincide con sus eventos origen y la pasada pudo terminarla.
     */
    public function isClean(): bool
    {
        return $this->divergences === 0 && $this->failures === 0;
    }
}
