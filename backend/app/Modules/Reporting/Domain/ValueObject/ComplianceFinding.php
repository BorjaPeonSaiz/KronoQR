<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Un incumplimiento del perfil de cumplimiento, medido sobre una jornada
 * (RN-10, RN-11, RN-12) o sobre una semana (RN-17).
 *
 * ## Los tres numeros, y por que son tres
 *
 * `measuredMinutes`, `thresholdMinutes` y `differenceMinutes` van los tres
 * aunque el tercero se deduzca de los dos primeros: con ellos la pantalla escribe
 * «10 h 00 de 12 h 00 (faltan 2 h 00)» **sin calcular nada** (regla dura 7
 * aplicada a la presentacion). Si el panel restara, tres clientes distintos
 * tendrian tres redondeos distintos.
 *
 * **Minutos enteros, nunca horas con decimales.** Es la unidad del calculo
 * (`duration_minutes`, RN-06) y la unica en la que las partes suman el total.
 *
 * `differenceMinutes` es siempre **positivo**: lo que falta de descanso en RN-10
 * y lo que sobra en las otras tres. Solo salen incumplimientos, asi que un cero o
 * un negativo describiria una jornada que cumple y no tendria por que estar aqui;
 * el constructor lo rechaza en lugar de dejarlo pasar, porque un hallazgo con
 * «faltan 0 minutos» es un aviso que nadie puede defender.
 *
 * ## `workDate` y `week` son excluyentes
 *
 * Las reglas diarias llevan la jornada y `week` nulo; la semanal lleva la semana
 * y `workDate` nulo. No es una comodidad de serializacion: son dos unidades de
 * medida distintas, y un hallazgo que llevara las dos no diria sobre cual se ha
 * medido.
 */
final readonly class ComplianceFinding
{
    private function __construct(
        public ComplianceRuleName $rule,
        public ComplianceEmployee $employee,
        /** Jornada medida (`YYYY-MM-DD`), o `null` en la regla semanal. */
        public ?string $workDate,
        /** Semana medida, o `null` en las reglas diarias. */
        public ?ComplianceWeek $week,
        public int $measuredMinutes,
        public int $thresholdMinutes,
        public int $differenceMinutes,
        /**
         * El tramo que señala el hallazgo: el que **abre** la jornada en RN-10
         * —el que empezo antes de tiempo— y el tramo continuado en RN-12. Nulo en
         * RN-11 y RN-17, donde ningun tramo por si solo explica el exceso y
         * señalar uno arbitrario haria pensar que ese es el problema.
         */
        public ?string $shiftEntryUuid,
        public bool $hasOpenShift,
        /** La incidencia de la bandeja para el mismo hecho, si existe. */
        public ?ComplianceIncidentLink $incident = null,
    ) {
        if ($differenceMinutes < 1) {
            throw new InvalidArgumentException(
                'Un hallazgo de cumplimiento describe un incumplimiento, asi que la diferencia con el '
                .'umbral es de al menos un minuto; se recibio '.$differenceMinutes.'.'
            );
        }
    }

    /**
     * RN-10: el descanso entre jornadas se ha quedado corto. La diferencia es lo
     * que **falta**.
     */
    public static function insufficientRest(
        ComplianceEmployee $employee,
        string $workDate,
        int $restMinutes,
        int $thresholdMinutes,
        ?string $openingShiftEntryUuid,
        bool $hasOpenShift,
    ): self {
        return new self(
            rule: ComplianceRuleName::InsufficientRest,
            employee: $employee,
            workDate: $workDate,
            week: null,
            measuredMinutes: $restMinutes,
            thresholdMinutes: $thresholdMinutes,
            differenceMinutes: $thresholdMinutes - $restMinutes,
            shiftEntryUuid: $openingShiftEntryUuid,
            hasOpenShift: $hasOpenShift,
        );
    }

    /** RN-11: la suma de los tramos cerrados de la jornada. */
    public static function dailyExcess(
        ComplianceEmployee $employee,
        string $workDate,
        int $workedMinutes,
        int $thresholdMinutes,
        bool $hasOpenShift,
    ): self {
        return new self(
            rule: ComplianceRuleName::DailyExcess,
            employee: $employee,
            workDate: $workDate,
            week: null,
            measuredMinutes: $workedMinutes,
            thresholdMinutes: $thresholdMinutes,
            differenceMinutes: $workedMinutes - $thresholdMinutes,
            shiftEntryUuid: null,
            hasOpenShift: $hasOpenShift,
        );
    }

    /** RN-12: el tramo continuado mas largo de la jornada. */
    public static function missingBreak(
        ComplianceEmployee $employee,
        string $workDate,
        ComplianceShiftSegment $segment,
        int $thresholdMinutes,
        bool $hasOpenShift,
    ): self {
        return new self(
            rule: ComplianceRuleName::MissingBreak,
            employee: $employee,
            workDate: $workDate,
            week: null,
            measuredMinutes: $segment->minutes(),
            thresholdMinutes: $thresholdMinutes,
            differenceMinutes: $segment->minutes() - $thresholdMinutes,
            shiftEntryUuid: $segment->uuid,
            hasOpenShift: $hasOpenShift,
        );
    }

    /** RN-17: la suma de las siete jornadas de la semana del perfil. */
    public static function weeklyExcess(
        ComplianceEmployee $employee,
        ComplianceWeek $week,
        int $weeklyMinutes,
        int $thresholdMinutes,
        bool $hasOpenShift,
    ): self {
        return new self(
            rule: ComplianceRuleName::WeeklyExcess,
            employee: $employee,
            workDate: null,
            week: $week,
            measuredMinutes: $weeklyMinutes,
            thresholdMinutes: $thresholdMinutes,
            differenceMinutes: $weeklyMinutes - $thresholdMinutes,
            shiftEntryUuid: null,
            hasOpenShift: $hasOpenShift,
        );
    }

    /**
     * El mismo hallazgo con la incidencia de la bandeja enlazada.
     *
     * Se enlaza **despues** de evaluar y no durante: el evaluador es puro y no
     * conoce `incidents`, y quien resuelve el enlace es el caso de uso con su
     * puerto. Asi la aritmetica se puede probar sin base de datos.
     */
    public function linkedTo(?ComplianceIncidentLink $incident): self
    {
        return new self(
            rule: $this->rule,
            employee: $this->employee,
            workDate: $this->workDate,
            week: $this->week,
            measuredMinutes: $this->measuredMinutes,
            thresholdMinutes: $this->thresholdMinutes,
            differenceMinutes: $this->differenceMinutes,
            shiftEntryUuid: $this->shiftEntryUuid,
            hasOpenShift: $this->hasOpenShift,
            incident: $incident,
        );
    }
}
