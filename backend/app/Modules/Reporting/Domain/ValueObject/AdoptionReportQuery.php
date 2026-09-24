<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Lo que se le pide al cuadro de impacto y adopcion (**RF-IN-08**): **dos
 * periodos**, y el segundo no se pide.
 *
 * ## No hay alcance, ni departamento, ni empleado, y no es un olvido
 *
 * Al contrario que {@see PeriodReportQuery}, aqui no entra nada de eso. El cuadro
 * es de la **instalacion entera** por una razon de privacidad y no de comodidad
 * (regla dura 21, decision 12 de la ficha 3.13): con desglose por departamento,
 * un departamento de una persona convertiria «horas trabajadas» en su dato
 * individual, servido en una pantalla cuya finalidad es medir la adopcion del
 * sistema y no evaluar a nadie. Y por eso la policy es `{admin, rrhh}` y punto:
 * no hay nada que acotar, asi que no hay a quien acotar.
 *
 * ## El periodo anterior se **deriva**, nunca se recibe
 *
 * Es el mismo numero de dias inmediatamente antes de `from`: para marzo entero,
 * febrero entero; para una semana, la semana previa. Se calcula aqui y no en el
 * cliente para que la comparacion sea la misma en la pantalla, en el CSV y en el
 * PDF — tres calculos del «periodo anterior» son tres respuestas distintas a la
 * misma pregunta en cuanto uno de los tres redondee un mes a 30 dias.
 *
 * **No es «el mismo periodo del año anterior»**, que seria la comparacion
 * estacional que un hotel querria en algun momento y que la decision 10 de la
 * ficha deja fuera de alcance: contra el año pasado, un cuadro de marzo de 2026
 * de una instalacion puesta en marcha en enero no tendria con que comparar nunca.
 *
 * ## Fechas civiles en la zona del centro, como todo lo demas
 *
 * `work_date` es una fecha civil (RN-05, reglas duras 3 y 4): un turno de 22:00 a
 * 06:00 cuenta entero en la jornada de su hora de inicio. Quien resuelve «que dia
 * es hoy» y «cual fue el mes pasado» es la zona del **centro** (ADR-040), no la
 * del servidor ni la del navegador: a las 00:30 del 1 de abril en Madrid, el
 * servidor en UTC sigue en marzo y el cuadro por omision enseñaria febrero.
 */
final readonly class AdoptionReportQuery
{
    private function __construct(
        public DateRange $range,
        public DateRange $previousRange,
    ) {}

    /**
     * El cuadro de un periodo concreto, con su anterior ya derivado.
     */
    public static function of(DateRange $range): self
    {
        // El dia anterior a `from`, que es donde termina el periodo de
        // comparacion: pegados y sin solaparse ni dejar hueco.
        $previousTo = $range->from->modify('-1 day')->format('Y-m-d');

        return new self($range, DateRange::endingOn($previousTo, $range->days()));
    }

    /**
     * El cuadro del **mes natural anterior completo**, resuelto en la zona del
     * centro.
     *
     * Es la omision del endpoint, y es un mes cerrado a proposito: el mes en curso
     * daria un cuadro que cambia cada dia y cuyos porcentajes dependen de la hora
     * a la que se mire, que es justo lo contrario de lo que sirve para decidir si
     * el sistema esta funcionando. Mismo criterio que
     * `reporting:adoption-metrics`, que mide **ayer** y no hoy.
     *
     * @param  DateTimeImmutable  $now  El instante que da el puerto `Clock` (regla dura 2).
     * @param  string  $timeZone  Zona IANA del centro (ADR-040).
     */
    public static function previousMonth(DateTimeImmutable $now, string $timeZone): self
    {
        $local = $now->setTimezone(new DateTimeZone($timeZone));

        // `first day of` antes de restar el mes: sin eso, un 31 de marzo menos un
        // mes da el 3 de marzo —febrero no tiene 31— y el cuadro enseñaria marzo
        // a medias justo el ultimo dia del mes.
        $firstOfPreviousMonth = $local->modify('first day of last month');
        $lastOfPreviousMonth = $local->modify('last day of last month');

        return self::of(DateRange::between(
            $firstOfPreviousMonth->format('Y-m-d'),
            $lastOfPreviousMonth->format('Y-m-d'),
        ));
    }

    /**
     * El primer dia del mes de `$isoDate`, para completar un `to` que llego solo.
     *
     * Vive aqui y no en el `FormRequest` por lo mismo que la omision completa: es
     * una decision sobre **que periodo describe el cuadro**, y el borde no sabe
     * nada de periodos.
     */
    public static function startOfMonthOf(string $isoDate): string
    {
        return substr($isoDate, 0, 7).'-01';
    }
}
