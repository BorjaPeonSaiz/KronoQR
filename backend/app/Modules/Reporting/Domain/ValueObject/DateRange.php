<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Reporting\Domain\Exception\InvalidDateRange;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Un intervalo de **jornadas**, cerrado por los dos extremos.
 *
 * ## Fechas civiles, no instantes
 *
 * `work_date` es una fecha civil en la zona del centro (RN-05): el dia al que se
 * atribuye un turno, no un momento del tiempo. Filtrar por ella y no por la hora
 * de las marcas es lo que mantiene entero un turno 22:00 → 06:00 (regla dura 4):
 * pedir el dia 15 no puede devolver medio tramo que empezo el 14.
 *
 * Por dentro se guardan dos `DateTimeImmutable` fijados a medianoche UTC. La
 * zona **no significa nada aqui** y por eso se fija: son dos etiquetas de
 * calendario que solo se comparan entre si, y darles una zona variable
 * convertiria un `<=` en una pregunta sobre husos horarios.
 *
 * ## Por que hay un techo
 *
 * `MAXIMUM_DAYS` no es una regla de negocio —ninguna lo fija— sino un limite de
 * recursos: sin el, una URL manipulada pide el historico completo de una persona
 * en una sola respuesta. La retencion legal es de cuatro años (RL-02), asi que
 * quien necesite mas de un año pagina por rangos o usa la exportacion, que se
 * genera en diferido y para eso existe.
 */
final readonly class DateRange
{
    /**
     * Un año con margen para el bisiesto. El panel abre en un mes y el caso
     * ancho real es «el año pasado completo».
     */
    public const int MAXIMUM_DAYS = 366;

    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    /**
     * @param  string  $from  Fecha ISO `YYYY-MM-DD`.
     * @param  string  $to  Fecha ISO `YYYY-MM-DD`, inclusive.
     */
    public static function between(string $from, string $to): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start > $end) {
            throw new InvalidDateRange(
                'El rango de jornadas empieza en '.$from.' y termina en '.$to.': la primera fecha no puede ser posterior a la ultima.',
            );
        }

        $days = (int) $start->diff($end)->format('%a') + 1;

        if ($days > self::MAXIMUM_DAYS) {
            throw new InvalidDateRange(
                'El rango de jornadas abarca '.$days.' dias y el maximo es '.self::MAXIMUM_DAYS.'.',
            );
        }

        return new self($start, $end);
    }

    /**
     * Los `$days` dias que **terminan** en `$to`, contandolo a el.
     *
     * Es el rango por omision del detalle de jornada: `endingOn('2026-03-31', 31)`
     * son marzo entero, no marzo mas un dia.
     */
    public static function endingOn(string $to, int $days): self
    {
        if ($days < 1) {
            throw new InvalidDateRange('Un rango de jornadas tiene al menos un dia, y se pidieron '.$days.'.');
        }

        $end = self::parse($to);
        $start = $end->modify('-'.($days - 1).' days');

        return self::between($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    /**
     * Cuantos dias abarcaria el rango `[$from, $to]`, **sin aplicar el techo**.
     *
     * Existe para quien tiene su propio techo y quiere responder con su propio
     * error: el cuadro de impacto (RF-IN-08) responde `422` con
     * `urn:kronoqr:problem:report-too-large` cuando se piden mas dias de los que
     * entrega en el acto, y construyendo el rango primero recibiria el
     * `InvalidDateRange` de {@see self::MAXIMUM_DAYS} —un `validation-failed`— que
     * no remite a nada.
     *
     * **No es una puerta trasera al techo.** Devuelve un entero; para tener un
     * rango sigue habiendo que pasar por {@see self::between()}, que lo aplica.
     * Las dos fechas se validan igual, asi que «2026-02-30» sigue siendo un error.
     */
    public static function spanInDays(string $from, string $to): int
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start > $end) {
            throw new InvalidDateRange(
                'El rango de jornadas empieza en '.$from.' y termina en '.$to.': la primera fecha no puede ser posterior a la ultima.',
            );
        }

        return (int) $start->diff($end)->format('%a') + 1;
    }

    public function isoFrom(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function isoTo(): string
    {
        return $this->to->format('Y-m-d');
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->format('%a') + 1;
    }

    /**
     * Rechaza «2026-02-30» y «14/03/2026», que `DateTimeImmutable` aceptaria
     * reinterpretando la primera y fallando la segunda con otro mensaje.
     */
    private static function parse(string $isoDate): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $isoDate,
            new DateTimeZone('UTC'),
        );

        if ($parsed === false || $parsed->format('Y-m-d') !== $isoDate) {
            throw new InvalidDateRange('«'.$isoDate.'» no es una fecha ISO valida (YYYY-MM-DD).');
        }

        return $parsed;
    }
}
