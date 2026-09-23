<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Reporting\Domain\Exception\InvalidIsoWeek;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Una semana del calendario ISO 8601: **de lunes a domingo**, rotulada
 * `AAAA-Www` (RF-PR-05, tarea 3.12).
 *
 * ## Por que existe, si ya hay {@see DateRange}
 *
 * Porque «la semana pasada» no es un rango de siete dias cualquiera y el sitio
 * donde se decide cual es no puede ser una resta de `-7 days` repartida por tres
 * clases. Tres cosas que aqui estan juntas y ahi no podrian estarlo:
 *
 * 1. **Empieza en lunes**, que es lo que ISO 8601 fija y lo que el informe por
 *    periodo ya usa con `granularity=week`. Con el domingo como primer dia —la
 *    otra convencion viva— el resumen del lunes hablaria de una semana distinta
 *    de la que enseña el panel.
 * 2. **El año de la semana no es el año de sus dias.** El 1 de enero de 2027 cae
 *    en viernes, asi que pertenece a `2026-W53`; y el 31 de diciembre de 2025 es
 *    miercoles y pertenece a `2026-W01`. Rotular con `format('Y')` en lugar de
 *    `format('o')` produce «semana 1 de 2025» para dias de 2026, que es el fallo
 *    que la prueba de la semana a caballo del año fija.
 * 3. **Hay años de 53 semanas y años de 52.** `2026-W53` no existe, y sin
 *    comprobarlo `setISODate()` lo reinterpreta en silencio como la primera de
 *    2027.
 *
 * ## Es una semana CIVIL, no un intervalo de instantes
 *
 * Igual que {@see DateRange}: lo que transporta son fechas, no momentos. La zona
 * del centro decide **que dia es hoy** —y por tanto cual es la semana pasada—,
 * pero eso ocurre antes de llegar aqui, en el caso de uso, que es quien tiene el
 * reloj por el puerto `Clock` (regla dura 2) y el centro por su puerto. Aqui
 * dentro solo se hace aritmetica de calendario, y por eso el `DateTimeZone` es
 * siempre UTC: no representa ningun instante que convertir.
 */
final readonly class IsoWeek
{
    /** `AAAA-Www`, como lo escribe quien pasa `--week=` y como lo rotula el correo. */
    private const string LABEL_SHAPE = '/^([0-9]{4})-W(0[1-9]|[1-4][0-9]|5[0-3])$/';

    private function __construct(
        /** Año **ISO** de la semana (`format('o')`), que puede no ser el de sus dias. */
        public int $year,
        /** Numero de semana, de 1 a 53. */
        public int $week,
    ) {}

    /**
     * La semana que contiene esa fecha civil.
     *
     * @param  DateTimeImmutable  $day  Un dia ya resuelto en la zona del centro.
     */
    public static function containing(DateTimeImmutable $day): self
    {
        return new self((int) $day->format('o'), (int) $day->format('W'));
    }

    /**
     * La semana escrita `AAAA-Www`.
     *
     * @throws InvalidIsoWeek si la forma no es esa o si esa semana no existe en
     *                        ese año —`2026-W53`, que tiene 52—
     */
    public static function fromLabel(string $label): self
    {
        if (preg_match(self::LABEL_SHAPE, $label, $match) !== 1) {
            throw new InvalidIsoWeek('Una semana se escribe AAAA-Www, y se recibio "'.$label.'".');
        }

        $week = new self((int) $match[1], (int) $match[2]);

        // La comprobacion que `setISODate()` no hace: con la semana 53 de un año
        // de 52, devuelve la primera del siguiente sin avisar. Si el viaje de
        // ida y vuelta no conserva el rotulo, la semana no existe.
        if (self::containing($week->firstDay())->label() !== $week->label()) {
            throw new InvalidIsoWeek('El año '.$week->year.' no tiene la semana '.$week->week.'.');
        }

        return $week;
    }

    /**
     * La semana anterior, cruzando el año cuando toca.
     *
     * Se calcula retrocediendo siete dias desde su lunes y volviendo a preguntar
     * por la semana de ese dia, y no restando uno al contador: `2027-W01`
     * menos uno seria `2027-W00`, y la respuesta correcta es `2026-W53`.
     */
    public function previous(): self
    {
        return self::containing($this->firstDay()->modify('-7 days'));
    }

    /** `2026-W38`, con la semana a dos cifras. */
    public function label(): string
    {
        return $this->year.'-W'.str_pad((string) $this->week, 2, '0', STR_PAD_LEFT);
    }

    /** El lunes, como fecha ISO `AAAA-MM-DD`. */
    public function isoStart(): string
    {
        return $this->firstDay()->format('Y-m-d');
    }

    /** El domingo, como fecha ISO `AAAA-MM-DD`. Inclusive. */
    public function isoEnd(): string
    {
        return $this->firstDay()->modify('+6 days')->format('Y-m-d');
    }

    /** Los siete dias, como el rango que entiende el informe por periodo. */
    public function toRange(): DateRange
    {
        return DateRange::between($this->isoStart(), $this->isoEnd());
    }

    /**
     * El lunes de la semana, a mediodia en UTC.
     *
     * **A mediodia y no a medianoche** por prudencia aritmetica: los `modify()`
     * de esta clase son de dias enteros sobre una fecha civil, y un instante
     * lejos de los bordes no puede acabar en el dia de al lado por ninguna
     * conversion. No representa ningun momento real: solo el dia se usa.
     */
    private function firstDay(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2000-01-01 12:00:00', new DateTimeZone('UTC')))
            ->setISODate($this->year, $this->week, 1);
    }
}
