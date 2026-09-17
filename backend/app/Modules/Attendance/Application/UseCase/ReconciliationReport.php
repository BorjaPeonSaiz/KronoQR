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
        /**
         * Sospechas que la relectura con la fila bloqueada no confirmo.
         *
         * **No son divergencias y por eso van en su propio contador.** Salen de
         * que la inspeccion lee el registro horario y la proyeccion en dos
         * consultas distintas: un fichaje que confirma entre las dos le deja una
         * mitad nueva y otra vieja. Que se cuenten aparte es lo que permite
         * distinguir «la proyeccion estaba rota» de «la pasada se cruzo con el
         * turno de noche», y lo segundo no puede encender la alerta de
         * integridad ni teñir de rojo el codigo de salida.
         */
        public int $selfResolved,
        public array $byField,
    ) {}

    /**
     * Antes de la puesta en marcha no hay centro y por tanto no hay zona horaria
     * con la que decir a que jornada pertenece un turno (RF-PD-03, RN-05). No es
     * un fallo: no hay nada que reconciliar.
     */
    public static function withoutSite(): self
    {
        return new self(false, '', '', 0, 0, 0, 0, 0, 0, []);
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
        int $selfResolved,
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
            $selfResolved,
            $byField,
        );
    }

    /**
     * La proyeccion coincide con sus eventos origen y la pasada pudo terminarla.
     *
     * **`selfResolved` no la ensucia**: una sospecha que se deshace al releer no
     * dejo nada escrito ni nada por escribir, y hacer terminar el comando en rojo
     * por ella convertiria el turno de noche en un fallo nocturno recurrente.
     */
    public function isClean(): bool
    {
        return $this->divergences === 0 && $this->failures === 0;
    }
}
