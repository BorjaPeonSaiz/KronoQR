<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Port\ProjectedDailyTotal;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use DateTimeImmutable;

/**
 * Lo que `daily_totals` afirma frente a lo que dicen los tramos vigentes
 * (RF-PR-02, ADR-007).
 *
 * **Se compara campo a campo y no solo el total.** Un dia con las horas
 * correctas y `has_open_shift` mal puesto deja el panel de presencia contando
 * gente que ya se fue, y una `last_out_at` de mas convierte un turno en curso en
 * uno terminado. Los seis campos del doc 01 §5.5 se afirman igual, asi que los
 * seis se comprueban igual.
 *
 * **`recalculated_at` NO entra en la comparacion**: es cuando se escribio la
 * fila, no lo que la fila afirma. Compararla haria divergente toda jornada por
 * el mero paso del tiempo.
 *
 * **La ausencia de fila es una divergencia**, y de las peores: una jornada con
 * tramos que no aparece en la proyeccion es un dia que el panel muestra vacio y
 * que el informe no suma. Se marca con el campo `row` para distinguirla de un
 * total equivocado.
 */
final readonly class DailyTotalsDivergence
{
    private const string MISSING_ROW = 'row';

    /**
     * @param  list<string>  $fields  columnas que no coinciden
     */
    private function __construct(
        /** El estado que dicen los tramos vigentes: lo que se va a escribir. */
        public DailyTotalsRecalculated $expected,
        /** Lo que habia escrito, o `null` si no habia fila. */
        public ?ProjectedDailyTotal $actual,
        public array $fields,
    ) {}

    /**
     * La divergencia entre lo que dice el registro horario y lo que dice la
     * proyeccion, o `null` cuando coinciden — que es lo que tiene que pasar
     * siempre (regla dura 7).
     */
    public static function between(DailyTotalsRecalculated $expected, ?ProjectedDailyTotal $actual): ?self
    {
        if (! $actual instanceof ProjectedDailyTotal) {
            return new self($expected, null, [self::MISSING_ROW]);
        }

        $fields = self::differingFields($expected, $actual);

        return $fields === [] ? null : new self($expected, $actual, $fields);
    }

    public function employeeUuid(): string
    {
        return $this->expected->employeeUuid;
    }

    public function workDate(): string
    {
        return $this->expected->workDate->isoDate;
    }

    public function rowWasMissing(): bool
    {
        return $this->actual === null;
    }

    /**
     * @return list<string>
     */
    private static function differingFields(DailyTotalsRecalculated $expected, ProjectedDailyTotal $actual): array
    {
        $fields = [];

        if ($expected->total->minutes !== $actual->totalMinutes) {
            $fields[] = 'total_minutes';
        }

        if ($expected->shiftCount !== $actual->shiftCount) {
            $fields[] = 'shift_count';
        }

        if (! self::sameInstant($expected->firstClockInAt, $actual->firstClockInAt)) {
            $fields[] = 'first_in_at';
        }

        if (! self::sameInstant($expected->lastClockOutAt, $actual->lastClockOutAt)) {
            $fields[] = 'last_out_at';
        }

        if ($expected->hasOpenShift !== $actual->hasOpenShift) {
            $fields[] = 'has_open_shift';
        }

        if ($expected->hasAnomaly !== $actual->hasIncident) {
            $fields[] = 'has_incident';
        }

        return $fields;
    }

    /**
     * Dos instantes son el mismo si lo son al microsegundo.
     *
     * Se comparan por su marca absoluta y no por su representacion: la columna
     * es `TIMESTAMPTZ` y el motor la devuelve en la zona de la sesion, asi que
     * `2026-03-15 06:00:00+01` y `2026-03-15 05:00:00+00` son el mismo instante y
     * no pueden contar como divergencia. `U.u` es la marca absoluta:
     * segundos desde epoch y microsegundos, sin zona que interpretar.
     */
    private static function sameInstant(?DateTimeImmutable $expected, ?DateTimeImmutable $actual): bool
    {
        if (! $expected instanceof DateTimeImmutable || ! $actual instanceof DateTimeImmutable) {
            return $expected === null && $actual === null;
        }

        return $expected->format('U.u') === $actual->format('U.u');
    }
}
