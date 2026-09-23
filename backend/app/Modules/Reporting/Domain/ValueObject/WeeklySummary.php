<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Lo que un responsable recibe por correo cada lunes (**RF-PR-05**, tarea 3.12).
 *
 * ## Nada se calcula aqui
 *
 * Las cifras son las del informe por periodo, tal cual: `worked`, `contracted`,
 * su desviacion, los dias con actividad, las ausencias y los festivos ya los
 * define {@see PeriodReportRow} y los sirve `daily_totals` (regla dura 7). Este
 * objeto **compone**: recorta a lo que cabe en un correo, suma los totales del
 * alcance y lleva al lado cuantas incidencias abiertas tiene ese responsable.
 * Si alguna cifra no cuadrara con el panel, la causa estaria en la proyeccion y
 * no aqui: dos formas de calcular el mismo total es como se acaba teniendo dos
 * totales.
 *
 * ## El recorte a cincuenta lineas es presentacion, no un filtro
 *
 * Un correo con doscientas filas no lo lee nadie y ademas rebota en muchos
 * servidores. Se detallan las primeras —el informe viene ordenado por persona— y
 * del resto se dice cuantas son, remitiendo al panel, que es donde se trabaja.
 * **Los totales se calculan sobre TODAS las filas**, no sobre las cincuenta
 * detalladas: un total que describiera solo el trozo visible seria una cifra
 * falsa con aspecto de cifra buena.
 *
 * El tope es el mismo que el de la lista de afectados del asiento de
 * `audit_log`, y no es casualidad: lo que se detalla en el correo es exactamente
 * lo que el trail enumera como divulgado.
 *
 * ## Ningun texto compara a nadie con nadie
 *
 * El resumen dice lo que cada persona trabajo y lo que tenia contratado. No
 * ordena por desviacion, no señala a quien mas se desvia y no califica: es
 * material para que el responsable revise su semana, no un cuadro de
 * rendimiento (doc 01 §12, RN-13; la valoracion del trabajo de alguien no es una
 * funcion de este producto).
 */
final readonly class WeeklySummary
{
    /**
     * Cuantas personas se detallan en el cuerpo antes de resumir el resto.
     *
     * Ver el docblock: es presentacion. Ninguna persona se cae del informe del
     * panel ni de los totales por no salir en el correo.
     */
    public const int MAXIMUM_DETAILED_LINES = 50;

    public function __construct(
        public IsoWeek $week,
        public PeriodReport $report,
        /**
         * Incidencias **abiertas** del alcance de quien lo recibe, sin detalle.
         *
         * Solo el numero: el detalle ya va en el aviso diario de RF-PR-01, y
         * repetirlo aqui seria un segundo correo con los mismos nombres. Lo que
         * este numero aporta es el contexto que falta en aquel —«tienes cuatro
         * sin resolver»— para que una bandeja que nadie mira no se quede
         * creciendo en silencio.
         */
        public int $openIncidents,
    ) {}

    /**
     * Las filas que se detallan en el correo.
     *
     * @return list<PeriodReportRow>
     */
    public function detailedRows(): array
    {
        return \array_slice($this->report->rows, 0, self::MAXIMUM_DETAILED_LINES);
    }

    /** Cuantas personas quedan sin detallar. Cero cuando caben todas. */
    public function undetailedRows(): int
    {
        return max(0, $this->report->rowCount() - self::MAXIMUM_DETAILED_LINES);
    }

    /** Personas distintas que aparecen en el resumen. */
    public function employeeCount(): int
    {
        return \count($this->report->employeeUuids());
    }

    public function isEmpty(): bool
    {
        return $this->report->rows === [];
    }

    /** Minutos trabajados por todo el alcance, sobre TODAS las filas. */
    public function workedMinutes(): int
    {
        return $this->report->workedMinutes();
    }

    /** Minutos contratados por todo el alcance, sobre TODAS las filas. */
    public function contractedMinutes(): int
    {
        return array_sum(array_map(
            static fn (PeriodReportRow $row): int => $row->contractedMinutes,
            $this->report->rows,
        ));
    }

    /**
     * Desviacion del alcance, con signo.
     *
     * **No se llama «horas extra»** y el correo no lo llama asi: es tiempo por
     * encima o por debajo de lo contratado en la semana. Que sea o no hora
     * extraordinaria lo decide el convenio, con compensaciones y periodos de
     * referencia que este producto no modela.
     */
    public function deviationMinutes(): int
    {
        return $this->workedMinutes() - $this->contractedMinutes();
    }
}
