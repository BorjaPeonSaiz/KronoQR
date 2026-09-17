<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\ComplianceRule;

/**
 * Como se llama cada regla del perfil **en la vista de cumplimiento**
 * (RF-PA-06).
 *
 * ## Por que hay dos vocabularios y no uno
 *
 * `Shared\Domain\ValueObject\ComplianceRule` nombra la regla por su
 * identificador del doc 01 (`RN-10`), que es como la cita una inspeccion.
 * `incidents.type` la nombra por lo que aterriza en la bandeja
 * (`insufficient_rest`). Esta vista habla el segundo, y a proposito: el hallazgo
 * de la pantalla y la incidencia de la bandeja describen el mismo hecho, y si se
 * llamaran distinto el panel tendria que traducir para poder enlazarlos —que es
 * como se acaba enlazando mal—.
 *
 * **Los nombres coinciden con `incidents.type` en tres de las cuatro, y en la
 * cuarta no puede ser.** RN-11 es `long_shift` en la bandeja, donde ese tipo
 * cubre ademas el tramo suelto desmedido de RN-08; aqui se llama `daily_excess`
 * porque es lo que mide. La traduccion vive en {@see self::incidentType()}, en un
 * solo sitio y con esta explicacion al lado.
 *
 * `weekly_excess` no tiene tipo de incidencia y nunca lo tendra: RN-17 no abre
 * ninguna ({@see ComplianceRule::opensIncident()}).
 *
 * El valor de cada caso es el del contrato (`ComplianceRuleName`).
 */
enum ComplianceRuleName: string
{
    /** RN-10: descanso entre jornadas por debajo del minimo. */
    case InsufficientRest = 'insufficient_rest';

    /** RN-11: la suma de los tramos cerrados de la jornada supera la ordinaria. */
    case DailyExcess = 'daily_excess';

    /** RN-12: tramo continuado por encima del maximo sin pausa. */
    case MissingBreak = 'missing_break';

    /** RN-17: la semana del perfil supera la jornada semanal ordinaria. */
    case WeeklyExcess = 'weekly_excess';

    /**
     * Las cuatro **en el orden en que se enseñan**: RN-10, RN-11, RN-12, RN-17.
     *
     * No es `cases()` con otro nombre: el contrato promete ese orden en
     * `meta.rules[]` y en el orden de desempate de `data`, y el orden de
     * declaracion de un enum es una casualidad que nadie protege. Aqui se
     * declaran los dos juntos, asi que mover un caso no cambia lo que sale.
     *
     * @return list<self>
     */
    public static function inRequirementOrder(): array
    {
        return [self::InsufficientRest, self::DailyExcess, self::MissingBreak, self::WeeklyExcess];
    }

    public static function fromRule(ComplianceRule $rule): self
    {
        return match ($rule) {
            ComplianceRule::MinimumRestBetweenWorkDays => self::InsufficientRest,
            ComplianceRule::MaximumDailyWorkingTime => self::DailyExcess,
            ComplianceRule::BreakInContinuousShift => self::MissingBreak,
            ComplianceRule::MaximumWeeklyWorkingTime => self::WeeklyExcess,
        };
    }

    /**
     * La regla de `Shared` de la que sale el umbral. Es el unico vocabulario que
     * `Reporting` comparte con `Attendance` y con `Product` (doc 02 §1.6).
     */
    public function rule(): ComplianceRule
    {
        return match ($this) {
            self::InsufficientRest => ComplianceRule::MinimumRestBetweenWorkDays,
            self::DailyExcess => ComplianceRule::MaximumDailyWorkingTime,
            self::MissingBreak => ComplianceRule::BreakInContinuousShift,
            self::WeeklyExcess => ComplianceRule::MaximumWeeklyWorkingTime,
        };
    }

    /** El `RN-*` del documento 01, que es como lo nombra quien defiende el aviso. */
    public function requirement(): string
    {
        return $this->rule()->value;
    }

    /**
     * El `incidents.type` que describe el mismo hecho, o `null` si la regla no
     * abre incidencia.
     *
     * Se usa para buscar la incidencia de la bandeja que corresponde a un
     * hallazgo. `long_shift` cubre alli dos reglas distintas (RN-08 y RN-11) y
     * eso es correcto para lo que se busca: si existe una incidencia de tramo
     * desmedido en esa misma jornada, es la que quien revisa tiene que abrir.
     */
    public function incidentType(): ?string
    {
        return match ($this) {
            self::InsufficientRest => 'insufficient_rest',
            self::DailyExcess => 'long_shift',
            self::MissingBreak => 'missing_break',
            self::WeeklyExcess => null,
        };
    }

    /** Si la regla mide una **semana** en lugar de una jornada. */
    public function isWeekly(): bool
    {
        return $this === self::WeeklyExcess;
    }
}
