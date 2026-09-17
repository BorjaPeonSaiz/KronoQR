<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Policy;

use App\Modules\Reporting\Domain\ValueObject\ComplianceEmployee;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFinding;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleName;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleStatus;
use App\Modules\Reporting\Domain\ValueObject\ComplianceShiftSegment;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSuspensionReason;
use App\Modules\Reporting\Domain\ValueObject\ComplianceWeek;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use App\Modules\Shared\Domain\ValueObject\ComplianceRule;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;

/**
 * Que jornadas y que semanas del periodo incumplen el perfil de cumplimiento
 * (**RF-PA-06**; RN-10, RN-11, RN-12 y RN-17).
 *
 * ## Puro, y sin reloj
 *
 * No hay reloj del sistema ni puerto `Clock` en este fichero, y no es un
 * descuido: aqui no se pregunta nada sobre el presente. Todo lo que se mide —un descanso, una
 * jornada, un tramo, una semana— ya ocurrio y viene en los hechos. El instante de
 * generacion de la respuesta es del caso de uso, que si tiene el puerto (regla
 * dura 2).
 *
 * ## Las comparaciones no viven aqui
 *
 * Los cuatro `if` preguntan a {@see CompliancePolicy}, que es donde estan escritos
 * los limites abiertos del doc 01 y donde los lee tambien la revision diaria de
 * `Attendance`. Es la mitad que hace que la vista y la bandeja **cuenten lo
 * mismo** (paso 4 de la ficha): con un `<` propio aqui, bastaria tocar uno de los
 * dos para que la pantalla señalara una jornada que la bandeja no, y quien lo
 * descubriria seria un empleado defendiendose de un aviso.
 *
 * ## Que cuenta cada regla, exactamente
 *
 * | Regla | Sobre que | De donde sale |
 * |---|---|---|
 * | RN-10 | Hueco entre la ultima salida de la jornada **anterior** y la primera entrada de esta | Resta de instantes UTC |
 * | RN-11 | Suma de los tramos **cerrados** de la jornada | `daily_totals.total_minutes` |
 * | RN-12 | Tramo **cerrado** mas largo | Resta de instantes UTC |
 * | RN-17 | Suma de las siete jornadas de la semana del perfil | Suma de `total_minutes` |
 *
 * **Ni un solo total se recalcula desde `shift_entries`** (regla dura 7): el
 * diario sale de la proyeccion y el semanal es la suma de siete diarios, asi que
 * las partes suman el total por construccion.
 *
 * **Restando instantes UTC, nunca fechas civiles** (RN-09). El sabado del cambio
 * de hora de marzo, salir a las 23:00 y entrar el domingo a las 10:00 son **10 h
 * reales** y no 11: la hora que el reloj se salta no se descansa. Y la semana no
 * se desplaza, porque se compone de `work_date`, que son etiquetas de calendario.
 *
 * ## Semanas completas, aunque el rango las corte
 *
 * Toda semana del perfil que toque `[from, to]` se evalua sobre sus **siete**
 * dias, incluidos los que caen fuera del rango —el lector los carga a proposito—.
 * Evaluar solo la parte de dentro daria totales que no suman lo que la persona ve
 * en su registro, y una semana de tres dias no se puede comparar con un umbral
 * semanal.
 *
 * ## Las reglas suspendidas no emiten
 *
 * RN-12 lo esta mientras el fichaje de pausa siga desactivado en esta
 * instalacion ({@see ComplianceRuleSuspension}, ADR-024, RF-AT-12). No se calla:
 * su umbral viaja en `meta.rules[]` con `evaluated: false` y su motivo, para que
 * la pantalla pueda decir por que no ve avisos de pausas. En cuanto el hotel
 * active `ATTENDANCE_BREAK_CLOCKING`, esta clase empieza a emitirla sin tocar
 * una linea: la vista recalcula siempre con lo vigente.
 */
final readonly class ComplianceEvaluation
{
    /**
     * @param  CompliancePolicy  $policy  los cuatro umbrales del perfil del centro, ya
     *                                    resueltos (regla dura 14)
     * @param  ComplianceRuleSuspension  $suspension  que reglas no abren incidencia en esta
     *                                                instalacion. Desde la tarea 3.5 depende de
     *                                                `ATTENDANCE_BREAK_CLOCKING`, asi que la
     *                                                inyecta `ReadComplianceSummary`, que es quien
     *                                                alcanza los ajustes: el evaluador no pregunta
     *                                                nada, se le dice
     */
    public function __construct(
        private CompliancePolicy $policy,
        private ComplianceRuleSuspension $suspension,
    ) {}

    /**
     * Los hallazgos del periodo, ordenados por persona, jornada o semana y regla.
     *
     * @param  list<ComplianceFacts>  $facts  jornadas del alcance, del rango **ampliado** hasta
     *                                        completar las semanas del borde. La jornada anterior
     *                                        a `from` NO viene como fila: viaja dentro de cada
     *                                        `ComplianceFacts::$previousLastOutAt`, que es lo unico
     *                                        que RN-10 necesita de ella
     * @return list<ComplianceFinding>
     */
    public function evaluate(array $facts, DateRange $range): array
    {
        $findings = [];

        foreach ($this->byEmployee($facts) as $employeeFacts) {
            foreach ($this->daily($employeeFacts, $range) as $finding) {
                $findings[] = $finding;
            }

            foreach ($this->weekly($employeeFacts, $range) as $finding) {
                $findings[] = $finding;
            }
        }

        return $this->sorted($findings);
    }

    /**
     * Las cuatro reglas con su umbral y si se evaluan, en el orden RN-10, RN-11,
     * RN-12, RN-17.
     *
     * Se construyen **aqui** y no en el caso de uso porque describen exactamente
     * lo que esta clase ha hecho: si un dia dejara de evaluar una regla, el
     * criterio publicado cambiaria con ella y no un despliegue mas tarde.
     *
     * @return list<ComplianceRuleStatus>
     */
    public function appliedRules(): array
    {
        return array_map(
            fn (ComplianceRuleName $name): ComplianceRuleStatus => new ComplianceRuleStatus(
                rule: $name,
                thresholdMinutes: $this->thresholdOf($name),
                evaluated: $this->evaluates($name),
                // Hoy solo hay un motivo de suspension y por eso no hay `match`:
                // el dia que haya dos, el enum crece y esta linea se convierte en
                // uno. Un `match` con un solo caso seria adivinar cual sera el
                // segundo. El motivo es el de verdad —«el fichaje de pausa esta
                // desactivado en esta instalacion»— y no «esperando a la 3.5».
                suspensionReason: $this->evaluates($name)
                    ? null
                    : ComplianceSuspensionReason::BreakClockingDisabled,
            ),
            ComplianceRuleName::inRequirementOrder(),
        );
    }

    public function thresholdOf(ComplianceRuleName $name): int
    {
        return match ($name->rule()) {
            ComplianceRule::MinimumRestBetweenWorkDays => $this->policy->minimumRestMinutes,
            ComplianceRule::MaximumDailyWorkingTime => $this->policy->maximumDailyMinutes,
            ComplianceRule::BreakInContinuousShift => $this->policy->breakRequiredAfterMinutes,
            ComplianceRule::MaximumWeeklyWorkingTime => $this->policy->maximumWeeklyMinutes,
        };
    }

    /**
     * Las tres reglas diarias, **solo sobre las jornadas dentro del rango**.
     *
     * Las de fuera estan cargadas para dar contexto —el descanso de la primera y
     * los dias que completan las semanas del borde— y señalarlas seria enseñar
     * alertas de un periodo que no se ha pedido.
     *
     * @param  list<ComplianceFacts>  $facts
     * @return list<ComplianceFinding>
     */
    private function daily(array $facts, DateRange $range): array
    {
        $findings = [];

        foreach ($facts as $day) {
            if ($day->workDate < $range->isoFrom() || $day->workDate > $range->isoTo()) {
                continue;
            }

            foreach ([$this->rest($day), $this->dailyTotal($day), $this->continuousShift($day)] as $finding) {
                if ($finding instanceof ComplianceFinding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /** RN-10, sobre el hueco con la jornada anterior. */
    private function rest(ComplianceFacts $day): ?ComplianceFinding
    {
        $rest = $day->restMinutes();

        if (! $this->evaluates(ComplianceRuleName::InsufficientRest)
            || $rest === null
            || ! $this->policy->restIsInsufficient($rest)) {
            return null;
        }

        return ComplianceFinding::insufficientRest(
            employee: $day->employee,
            workDate: $day->workDate,
            restMinutes: $rest,
            thresholdMinutes: $this->policy->minimumRestMinutes,
            openingShiftEntryUuid: $day->openingShiftEntryUuid,
            hasOpenShift: $day->hasOpenShift,
        );
    }

    /** RN-11, sobre la suma de los tramos cerrados del dia. */
    private function dailyTotal(ComplianceFacts $day): ?ComplianceFinding
    {
        if (! $this->evaluates(ComplianceRuleName::DailyExcess)
            || ! $this->policy->dailyTimeIsExcessive($day->totalMinutes)) {
            return null;
        }

        return ComplianceFinding::dailyExcess(
            employee: $day->employee,
            workDate: $day->workDate,
            workedMinutes: $day->totalMinutes,
            thresholdMinutes: $this->policy->maximumDailyMinutes,
            hasOpenShift: $day->hasOpenShift,
        );
    }

    /** RN-12, sobre el tramo cerrado mas largo. */
    private function continuousShift(ComplianceFacts $day): ?ComplianceFinding
    {
        $segment = $day->longestClosedSegment;

        if (! $this->evaluates(ComplianceRuleName::MissingBreak)
            || ! $segment instanceof ComplianceShiftSegment
            || ! $this->policy->continuousShiftNeedsBreak($segment->minutes())) {
            return null;
        }

        return ComplianceFinding::missingBreak(
            employee: $day->employee,
            workDate: $day->workDate,
            segment: $segment,
            thresholdMinutes: $this->policy->breakRequiredAfterMinutes,
            hasOpenShift: $day->hasOpenShift,
        );
    }

    /**
     * RN-17 sobre cada semana del perfil que toque el rango.
     *
     * Las semanas salen del **rango**, no de las jornadas: una persona que no
     * ficho ningun dia de una semana tiene cero minutos esa semana y no incumple,
     * y deducir las semanas de sus jornadas daria el mismo resultado con mas
     * trabajo. Lo que si sale de las jornadas es el total, sumando los siete dias
     * que existan.
     *
     * @param  list<ComplianceFacts>  $facts
     * @return list<ComplianceFinding>
     */
    private function weekly(array $facts, DateRange $range): array
    {
        if (! $this->evaluates(ComplianceRuleName::WeeklyExcess)) {
            return [];
        }

        /** @var array<string, ComplianceFacts> $byDate */
        $byDate = [];

        foreach ($facts as $day) {
            $byDate[$day->workDate] = $day;
        }

        $findings = [];

        foreach ($this->weeksTouching($range) as $week) {
            $minutes = 0;
            $hasOpenShift = false;
            $employee = null;

            foreach ($week->days() as $date) {
                if (! isset($byDate[$date])) {
                    continue;
                }

                $minutes += $byDate[$date]->totalMinutes;
                $hasOpenShift = $hasOpenShift || $byDate[$date]->hasOpenShift;
                $employee = $byDate[$date]->employee;
            }

            if (! $employee instanceof ComplianceEmployee || ! $this->policy->weeklyTimeIsExcessive($minutes)) {
                continue;
            }

            $findings[] = ComplianceFinding::weeklyExcess(
                employee: $employee,
                week: $week,
                weeklyMinutes: $minutes,
                thresholdMinutes: $this->policy->maximumWeeklyMinutes,
                hasOpenShift: $hasOpenShift,
            );
        }

        return $findings;
    }

    /**
     * Las semanas del perfil que tocan el rango, de la primera a la ultima.
     *
     * Se avanza de siete en siete desde la semana que contiene `from`: con
     * `week_starts_on` a 7 (domingo) o a cualquier otro dia, la primera semana
     * puede empezar hasta seis dias antes del rango y la ultima terminar hasta
     * seis despues. Las dos se evaluan enteras (decision 6 de la ficha).
     *
     * @return list<ComplianceWeek>
     */
    private function weeksTouching(DateRange $range): array
    {
        $week = ComplianceWeek::containing($range->isoFrom(), $this->policy->weekStartsOn);
        $weeks = [];

        // La condicion la contesta el propio objeto de valor —«¿tocas este
        // rango?»— y no una comparacion de cadenas escrita aqui: es la misma
        // pregunta que decide si una semana se evalua, y con dos formas de
        // hacerla bastaria tocar una para que el borde dejara de coincidir.
        while ($week->touches($range->isoFrom(), $range->isoTo())) {
            $weeks[] = $week;
            $week = $week->next();
        }

        return $weeks;
    }

    private function evaluates(ComplianceRuleName $name): bool
    {
        return ! $this->suspension->isSuspended($name->rule());
    }

    /**
     * Las jornadas agrupadas por persona, conservando el orden de fecha con el
     * que llegan.
     *
     * @param  list<ComplianceFacts>  $facts
     * @return list<list<ComplianceFacts>>
     */
    private function byEmployee(array $facts): array
    {
        $grouped = [];

        foreach ($facts as $day) {
            $grouped[$day->employee->uuid][] = $day;
        }

        return array_values($grouped);
    }

    /**
     * Orden estable: persona (apellidos, nombre, identificador), despues jornada o
     * semana, y por ultimo regla en el orden del documento.
     *
     * Estable de verdad: `usort` no lo es en PHP, asi que el desempate tiene que
     * llegar hasta un valor unico. Dos ejecuciones de la misma consulta no pueden
     * devolver las mismas filas en distinto orden, o quien compara dos capturas
     * creera que algo ha cambiado.
     *
     * @param  list<ComplianceFinding>  $findings
     * @return list<ComplianceFinding>
     */
    private function sorted(array $findings): array
    {
        usort($findings, static function (ComplianceFinding $a, ComplianceFinding $b): int {
            $order = [
                ComplianceRuleName::InsufficientRest->value => 0,
                ComplianceRuleName::DailyExcess->value => 1,
                ComplianceRuleName::MissingBreak->value => 2,
                ComplianceRuleName::WeeklyExcess->value => 3,
            ];

            // La semanal se ordena por el primer dia de su semana, que es lo que
            // la situa junto a las jornadas de esos mismos dias en la pantalla.
            // `workDate` y `week` son excluyentes y siempre hay uno de los dos:
            // lo garantizan las cuatro factorias de `ComplianceFinding`.
            $dateOf = static fn (ComplianceFinding $f): string => $f->workDate ?? ($f->week instanceof ComplianceWeek ? $f->week->startsOn : '');

            return [$a->employee->sortKey(), $dateOf($a), $order[$a->rule->value]]
                <=> [$b->employee->sortKey(), $dateOf($b), $order[$b->rule->value]];
        });

        return $findings;
    }
}
