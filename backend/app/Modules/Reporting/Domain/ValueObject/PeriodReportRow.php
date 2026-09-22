<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una fila del informe por periodo: **un sujeto, un tramo de calendario y lo que
 * paso en el** (**RF-IN-01**, RF-IN-02, RF-IN-03).
 *
 * ## Que cuenta y que no, dicho aqui una vez
 *
 * - **`workedMinutes` sale de `daily_totals` y no se recalcula** (regla dura 7,
 *   ADR-007). Si la cifra no cuadra con los tramos, el problema es la
 *   proyeccion y se arregla con `attendance:reconcile` (RF-PR-02), no sumando
 *   otra vez por otro camino: dos formas de calcular el mismo total es como se
 *   acaba teniendo dos totales.
 * - **Los tramos anulados y los supersedidos no estan**, porque la proyeccion no
 *   los cuenta: `daily_totals` se recalcula como suma de los tramos **vigentes**
 *   (ADR-026). Un dia cuyos tramos se anularon todos aparece con cero, no
 *   desaparece.
 * - **Un dia con turno abierto no aporta minutos por omision.** El tramo sin
 *   cerrar vale cero en la proyeccion —todavia no ha terminado—, asi que
 *   incluirlo daria una cifra a medias justo en la comparacion contra lo
 *   contratado. Con `include_open_shifts` esos dias entran con lo que ya tengan
 *   cerrado. En los dos casos el dia **cuenta como dia con actividad** y sale
 *   ademas en `openShiftDays`, para que quien lea el informe sepa por que falta
 *   una jornada.
 * - **Una incidencia sin resolver NO excluye nada.** Se cuenta en
 *   `incidentDays` y se deja a la vista. Descontar horas por una incidencia
 *   abierta seria decidir el resultado de una revision que todavia no se ha
 *   hecho (RN-08: detectar no corrige).
 * - **Los dias sin actividad aparecen con cero y no se omiten.** Es lo que exige
 *   `/informe-nuevo` por escrito: para un informe de absentismo, omitirlos es
 *   un error. Los produce `generate_series`, de modo que
 *   `daysWithActivity + daysWithoutActivity` es siempre `daysInPeriod`.
 *
 * ## Ausencias, festivos y absentismo (RF-GP-04)
 *
 * Los tres contadores nuevos los define `Reporting\Domain\Policy\AbsenteeismRule`
 * —nombrado en prosa y no con `{@see}` porque Pint lo resolveria a un `use` que
 * despues borraria por no usarse en codigo—, y esa clase es el enunciado: aqui
 * solo se transportan. En resumen: `absenceDays` son dias-persona de alta
 * cubiertos por una ausencia activa, `holidayDays` son de alta, festivos del
 * perfil y **sin** ausencia —si coinciden gana la ausencia, para que los dos
 * contadores de dias justificados sean disjuntos— y `unjustifiedAbsenceDays` son
 * de alta, sin actividad, sin ausencia y sin festivo.
 *
 * **`unjustifiedAbsenceDays` no es «dias que faltó a trabajar».** El producto no
 * conoce el cuadrante, asi que los dias de descanso semanal caen ahi dentro. El
 * limite esta explicado en `AbsenteeismRule` y viaja ademas en
 * `meta.criteria`, en el idioma de quien pide el informe: un numero asi no puede
 * salir de la API sin su advertencia al lado.
 *
 * ## Lo contratado y su desviacion (RF-IN-03)
 *
 * `contractedMinutes` es la suma, dia a dia del periodo, de las horas semanales
 * del contrato **vigente ese dia** repartidas por dia natural. Los dias sin
 * contrato vigente no suman nada y se cuentan aparte, en
 * {@see ContractCoverage}: se **informan**, no se inventan.
 *
 * `deviationMinutes` es `worked - contracted`, con signo. `overtimeMinutes` es
 * solo la parte positiva, porque «exceso de jornada» es una cantidad y no puede
 * ser negativa; trabajar de menos no es exceso negativo, es la desviacion.
 *
 * ## El periodo viene **recortado** al rango pedido
 *
 * Una semana a caballo del 1 de marzo, en un informe que empieza el 1, se
 * devuelve como `2026-03-01 → 2026-03-01`, no como la semana entera. La fila
 * describe **exactamente los dias que ha contado**: si dijera «semana del 23 de
 * febrero» con el total de un solo dia, quien la lee compararia siete dias con
 * uno.
 */
final readonly class PeriodReportRow
{
    public function __construct(
        public ReportSubject $subject,
        /** Primer dia contado, ya recortado al rango pedido. */
        public DateTimeImmutable $periodStart,
        /** Ultimo dia contado, inclusive. */
        public DateTimeImmutable $periodEnd,
        public int $workedMinutes,
        public int $shiftCount,
        public int $daysInPeriod,
        public int $daysWithActivity,
        public int $openShiftDays,
        public int $incidentDays,
        public int $contractedMinutes,
        /** Dias del periodo en los que la persona estaba de alta y sin contrato vigente. */
        public int $daysWithoutContract,
        /** Dias-persona de alta cubiertos por una ausencia activa (RF-GP-04). */
        public int $absenceDays,
        /** Dias-persona de alta, festivos del perfil y sin ausencia que los cubra (RF-GP-04). */
        public int $holidayDays,
        /** Dias-persona de alta, sin actividad, sin ausencia y sin festivo (RF-GP-04). */
        public int $unjustifiedAbsenceDays,
    ) {
        if ($this->periodStart > $this->periodEnd) {
            throw new InvalidArgumentException('El periodo de una fila no puede terminar antes de empezar.');
        }

        if ($this->daysWithActivity > $this->daysInPeriod) {
            throw new InvalidArgumentException('Una fila no puede tener mas dias con actividad que dias.');
        }

        // La invariante que ata el contador nuevo al que ya existia: el
        // absentismo no justificado es un SUBCONJUNTO de los dias sin actividad
        // —exige ademas estar de alta, sin ausencia y sin festivo—, asi que no
        // puede superarlo. Si alguna vez lo hiciera, el `FILTER` del SQL habria
        // dejado de exigir alguna de las tres condiciones y el informe estaria
        // acusando de absentismo dias con fichajes.
        if ($this->unjustifiedAbsenceDays > $this->daysWithoutActivity()) {
            throw new InvalidArgumentException(
                'Una fila no puede tener mas dias de absentismo no justificado que dias sin actividad.'
            );
        }
    }

    public function daysWithoutActivity(): int
    {
        return $this->daysInPeriod - $this->daysWithActivity;
    }

    /**
     * Con signo: negativa cuando se ha trabajado por debajo de lo contratado.
     */
    public function deviationMinutes(): int
    {
        return $this->workedMinutes - $this->contractedMinutes;
    }

    /**
     * Solo la parte positiva de la desviacion.
     *
     * **No es «horas extra» en sentido laboral** y el informe no lo llama asi en
     * ningun sitio: es tiempo trabajado por encima de lo contratado en el
     * periodo. Que sea o no hora extraordinaria lo decide el convenio, con
     * compensaciones, bolsas de horas y periodos de referencia que este producto
     * no modela y que ningun requisito le pide.
     */
    public function overtimeMinutes(): int
    {
        return max(0, $this->deviationMinutes());
    }

    public function isoPeriodStart(): string
    {
        return $this->periodStart->format('Y-m-d');
    }

    public function isoPeriodEnd(): string
    {
        return $this->periodEnd->format('Y-m-d');
    }
}
