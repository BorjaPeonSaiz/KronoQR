<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las marcas de una version del tramo —la de antes o la de despues de una
 * correccion— tal y como se leen en el documento (RN-13, RL-04).
 *
 * **Existe para que «antes» y «despues» quepan en una celda cada uno.** Un
 * documento tabular no puede tener cuatro columnas por cada lado de la
 * correccion sin volverse ilegible, y lo que un inspector necesita comparar es
 * una linea con otra: `2026-03-14 06:00 → 14:00 (08:00)` frente a
 * `2026-03-14 06:00 → 15:00 (09:00)`.
 *
 * **Ausente no es lo mismo que vacio.** En un alta no hay version anterior y en
 * una anulacion no hay posterior: el esquema de `shift_corrections` lo declara
 * con dos `CHECK`. Aqui se distingue con `none()`, que escribe un guion largo —
 * «no habia» — en vez de dejar la celda en blanco, que se lee como «no lo se».
 */
final readonly class ExportedMarks
{
    /** Lo que se escribe cuando esa version no existe. */
    private const string ABSENT = '—';

    private function __construct(
        private ?string $localClockedInAt,
        private ?string $localClockedOutAt,
        private ExportedDuration $duration,
        private bool $present,
    ) {}

    public static function of(
        ?string $localClockedInAt,
        ?string $localClockedOutAt,
        ExportedDuration $duration,
    ): self {
        return new self($localClockedInAt, $localClockedOutAt, $duration, true);
    }

    /** No habia version: un alta no tiene «antes» y una anulacion no tiene «despues». */
    public static function none(): self
    {
        return new self(null, null, ExportedDuration::absent(), false);
    }

    public function describe(): string
    {
        if (! $this->present) {
            return self::ABSENT;
        }

        return sprintf(
            '%s → %s (%s)',
            $this->localClockedInAt ?? self::ABSENT,
            // Un tramo sin salida sigue abierto: escribir la hora de entrada
            // otra vez, o un cero, diria que duro nada.
            $this->localClockedOutAt ?? self::ABSENT,
            $this->duration->minutes === null ? self::ABSENT : $this->duration->toClockText(),
        );
    }
}
