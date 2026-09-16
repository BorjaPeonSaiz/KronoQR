<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;

/**
 * La semana del perfil: **siete fechas civiles** desde `week_starts_on`
 * (RN-17).
 *
 * ## Siete fechas, no un intervalo de tiempo
 *
 * La semana se compone de `work_date`, que ya esta en la zona del centro con
 * RN-05 aplicada, asi que **aqui no hay ninguna conversion de zona horaria** y
 * esa ausencia es la garantia: el cambio de hora de marzo no desplaza la semana
 * ni un dia, porque no hay ningun instante de por medio. El domingo del cambio
 * horario tiene 23 h reales y sigue siendo un `work_date` como los demas.
 *
 * ## `week_starts_on` es ISO-8601, y el formato `N` tambien
 *
 * 1 es lunes y 7 domingo. Se calcula con aritmetica de dias y no con
 * `modify('monday this week')`, que solo sabe empezar en lunes: un perfil que
 * empiece la semana en domingo —comun fuera de Europa— quedaria desplazado un dia
 * sin que nada fallara.
 */
final readonly class ComplianceWeek
{
    private function __construct(
        /** Primer dia, `YYYY-MM-DD`. */
        public string $startsOn,
        /** Septimo dia, inclusive. */
        public string $endsOn,
    ) {}

    /**
     * La semana del perfil que **contiene** esa fecha.
     *
     * @param  string  $workDate  fecha civil `YYYY-MM-DD`
     * @param  int  $weekStartsOn  dia ISO-8601 en que empieza la semana (1 lunes … 7 domingo)
     */
    public static function containing(string $workDate, int $weekStartsOn): self
    {
        $day = self::asCivilDate($workDate);

        // Cuantos dias hay que retroceder desde este hasta el inicio de semana.
        // El modulo 7 sobre la diferencia ISO vale para los siete valores de
        // `weekStartsOn` sin ningun caso especial, que es justo lo que no hace
        // `modify('monday this week')`.
        $offset = (((int) $day->format('N')) - $weekStartsOn + 7) % 7;

        $start = $day->modify('-'.$offset.' days');

        return new self(
            $start->format('Y-m-d'),
            $start->modify('+6 days')->format('Y-m-d'),
        );
    }

    /**
     * Las siete fechas civiles de la semana, en orden.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $day = self::asCivilDate($this->startsOn);
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = $day->modify('+'.$i.' days')->format('Y-m-d');
        }

        return $days;
    }

    /** La semana siguiente del mismo perfil. */
    public function next(): self
    {
        $start = self::asCivilDate($this->startsOn)->modify('+7 days');

        return new self($start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d'));
    }

    /** La semana anterior del mismo perfil. */
    public function previous(): self
    {
        $start = self::asCivilDate($this->startsOn)->modify('-7 days');

        return new self($start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d'));
    }

    /** Si alguno de sus siete dias cae dentro del rango `[$from, $to]`. */
    public function touches(string $from, string $to): bool
    {
        return $this->startsOn <= $to && $this->endsOn >= $from;
    }

    /**
     * Las fechas civiles se fijan a medianoche UTC por lo mismo que en
     * {@see DateRange}: son etiquetas de calendario que solo se comparan entre
     * si, y darles una zona variable convertiria un `+6 days` en una pregunta
     * sobre husos horarios.
     */
    private static function asCivilDate(string $isoDate): DateTimeImmutable
    {
        return new DateTimeImmutable($isoDate.' 00:00:00', new DateTimeZone('UTC'));
    }
}
