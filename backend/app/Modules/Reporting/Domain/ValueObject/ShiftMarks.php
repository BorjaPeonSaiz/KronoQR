<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Las marcas de **una version** de un tramo, tal y como quedaron escritas en
 * `shift_corrections.before` / `.after` (RN-13, RL-04).
 *
 * **No lleva el nombre de nadie** (regla dura 21): describe horas. La persona
 * esta en el tramo y quien firmo, en {@see CorrectionAuthor}.
 *
 * `workedMinutes` viene **escrito** en el libro y no se recalcula al leer: el
 * historico tiene que poder responder «cuanto decia este tramo antes» aunque el
 * criterio de calculo cambie en una version futura del producto. Recalcularlo
 * aqui reescribiria el pasado cada vez que alguien abre la pantalla.
 */
final readonly class ShiftMarks
{
    public function __construct(
        public int $version,
        public DateTimeImmutable $clockedInAt,
        public ?DateTimeImmutable $clockedOutAt,
        public int $workedMinutes,
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException('La version de un tramo empieza en 1, y llego '.$version.'.');
        }

        if ($workedMinutes < 0) {
            throw new InvalidArgumentException('Un tramo no puede haber durado '.$workedMinutes.' minutos.');
        }
    }
}
