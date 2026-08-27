<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use DateTimeImmutable;

/**
 * Intervalo cerrado de trabajo: dos instantes UTC, fin estrictamente posterior
 * al inicio (doc 01 §5.3, RN-03).
 *
 * **Solo representa tramos cerrados.** Un tramo abierto no tiene fin, y darle
 * uno ficticio —«ahora», el final del dia— seria inventar una marca que nadie
 * produjo, que es justo lo que ADR-006 prohibe. `ShiftEntry` guarda por eso los
 * dos instantes y construye su `TimeRange` cuando lo hay.
 *
 * **El intervalo es semiabierto `[inicio, fin)`**, igual que el `tstzrange` por
 * defecto de PostgreSQL sobre el que opera `shift_entries_no_overlap`. Si el
 * dominio considerase solape lo que la base de datos no, o al reves, un tramo
 * que sale a las 14:00 y el siguiente que entra a las 14:00 pasaria por una
 * comprobacion y lo rechazaria la otra.
 *
 * **RN-09 sale gratis de aqui:** la duracion se mide sobre marcas de tiempo
 * absolutas (`getTimestamp()`), no restando horas locales. La noche del cambio
 * de hora, un turno de 22:00 a 06:00 mide 420 o 540 minutos segun el sentido
 * del salto, que es exactamente lo que la persona trabajo.
 */
final readonly class TimeRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        self::assertUtc('start', $start);
        self::assertUtc('end', $end);

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            throw ClockOutBeforeClockIn::between($start, $end);
        }
    }

    /**
     * Regla dura 3 y RN-04. Se comprueba el desplazamiento y no el nombre de la
     * zona para no rechazar un `+00:00` o una `Z` legitimos, que son el mismo
     * instante con otro nombre.
     */
    public static function assertUtc(string $field, DateTimeImmutable $instant): void
    {
        if ($instant->getOffset() !== 0) {
            throw InstantIsNotUtc::forField($field, $instant);
        }
    }

    /**
     * RN-09. Los segundos sobrantes se truncan aqui, una sola vez.
     */
    public function duration(): WorkedDuration
    {
        return WorkedDuration::ofMinutes(
            intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60)
        );
    }

    /**
     * RN-02, con la semantica `[inicio, fin)` de PostgreSQL: dos intervalos que
     * solo se tocan en el borde **no** se solapan.
     */
    public function overlaps(self $other): bool
    {
        return $this->start->getTimestamp() < $other->end->getTimestamp()
            && $other->start->getTimestamp() < $this->end->getTimestamp();
    }

    /**
     * Si el instante cae dentro del intervalo. El fin queda fuera, por la misma
     * razon que `overlaps()`.
     */
    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant->getTimestamp() >= $this->start->getTimestamp()
            && $instant->getTimestamp() < $this->end->getTimestamp();
    }

    /**
     * Si el intervalo termina despues del instante dado. Es la comprobacion que
     * necesita abrir un tramo: el nuevo tramo no tiene fin, asi que solapa con
     * todo lo que siga vivo despues de su inicio.
     */
    public function endsAfter(DateTimeImmutable $instant): bool
    {
        return $this->end->getTimestamp() > $instant->getTimestamp();
    }

    public function equals(self $other): bool
    {
        return $this->start->getTimestamp() === $other->start->getTimestamp()
            && $this->end->getTimestamp() === $other->end->getTimestamp();
    }
}
