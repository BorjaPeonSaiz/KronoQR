<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Las dos marcas de un tramo: la entrada, y la salida cuando la hay (doc 01
 * §5.5, `clocked_in_at` y `clocked_out_at`).
 *
 * **Por que no basta con `TimeRange`.** `TimeRange` solo representa tramos
 * cerrados, y con razon: un tramo abierto no tiene fin y darle uno ficticio
 * seria inventar una marca que nadie produjo (ADR-006). Pero una correccion
 * necesita hablar de los dos casos con un solo tipo —se corrige la entrada de un
 * tramo que sigue abierto, se cierra un tramo olvidado, se rectifica la salida
 * de uno cerrado—, y pasar `(DateTimeImmutable $in, ?DateTimeImmutable $out)`
 * sueltos por cinco firmas acaba con alguien invirtiendo el orden de los
 * argumentos.
 *
 * Asi que este objeto es «el estado temporal de un tramo», valido tanto abierto
 * como cerrado, y **construye su `TimeRange` cuando lo hay**: las dos
 * comprobaciones que importan —RN-03, la salida es posterior a la entrada, y
 * RN-04, todo instante en UTC— las sigue haciendo `TimeRange`, en un solo sitio.
 *
 * Es inmutable y no admite estados imposibles: una salida anterior a la entrada
 * no llega a existir.
 */
final readonly class ShiftTimes
{
    private function __construct(
        public DateTimeImmutable $clockedInAt,
        public ?DateTimeImmutable $clockedOutAt,
    ) {
        TimeRange::assertUtc('clockedInAt', $clockedInAt);

        if ($clockedOutAt !== null) {
            // Construirlo es la comprobacion: si la salida no es estrictamente
            // posterior a la entrada, esto lanza y el objeto no nace (RN-03).
            new TimeRange($clockedInAt, $clockedOutAt);
        }
    }

    /** Un tramo con entrada y sin salida todavia. */
    public static function open(DateTimeImmutable $clockedInAt): self
    {
        return new self($clockedInAt, null);
    }

    /** Un tramo con sus dos marcas. */
    public static function closed(DateTimeImmutable $clockedInAt, DateTimeImmutable $clockedOutAt): self
    {
        return new self($clockedInAt, $clockedOutAt);
    }

    /**
     * Las dos marcas tal como llegan de la peticion, donde la salida puede
     * faltar.
     */
    public static function of(DateTimeImmutable $clockedInAt, ?DateTimeImmutable $clockedOutAt): self
    {
        return new self($clockedInAt, $clockedOutAt);
    }

    /**
     * Las mismas marcas con otra entrada. Es lo que hace una correccion que solo
     * toca la hora de entrada.
     */
    public function withClockIn(DateTimeImmutable $clockedInAt): self
    {
        return new self($clockedInAt, $this->clockedOutAt);
    }

    /**
     * Las mismas marcas con otra salida: la correccion de la hora de salida y,
     * cuando antes no habia ninguna, el cierre manual de un tramo olvidado.
     */
    public function withClockOut(DateTimeImmutable $clockedOutAt): self
    {
        return new self($this->clockedInAt, $clockedOutAt);
    }

    public function isOpen(): bool
    {
        return $this->clockedOutAt === null;
    }

    /**
     * El intervalo, o `null` si el tramo sigue abierto.
     */
    public function period(): ?TimeRange
    {
        if ($this->clockedOutAt === null) {
            return null;
        }

        return new TimeRange($this->clockedInAt, $this->clockedOutAt);
    }

    /**
     * Lo que estas marcas aportan al total del dia. Un tramo abierto aporta
     * cero, igual que en `ShiftEntry`: el registro legal cuenta lo fichado, no
     * lo que va corriendo.
     */
    public function duration(): WorkedDuration
    {
        return $this->period()?->duration() ?? WorkedDuration::zero();
    }

    /**
     * Si estas marcas describen el mismo tramo que las otras.
     *
     * Se compara por instante absoluto y no por objeto: las 06:00 escritas como
     * `+00:00` y como `Z` son el mismo momento, y una correccion que solo
     * cambiara la forma de escribir la hora no es una correccion.
     */
    public function equals(self $other): bool
    {
        return $this->clockedInAt->getTimestamp() === $other->clockedInAt->getTimestamp()
            && $this->clockedOutAt?->getTimestamp() === $other->clockedOutAt?->getTimestamp();
    }
}
