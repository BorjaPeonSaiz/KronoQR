<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use DateTimeImmutable;
use DomainException;

/**
 * Se ha pedido la duracion de algo que acaba antes de empezar (RN-09).
 *
 * Es un error de quien llama: los agregados ya rechazan un tramo con la salida
 * anterior a la entrada (RN-03), asi que llegar aqui significa que alguien midio
 * sin pasar por ellos. Un hueco negativo entre dos cosas distintas no es este
 * error: lo resuelve `ElapsedMinutes::gapBetween()` devolviendo `null`.
 *
 * El mensaje lleva los dos instantes en UTC y nada mas: puede acabar en
 * `error_events`, que viaja al fabricante (regla dura 21).
 */
final class InvalidElapsedTime extends DomainException
{
    public static function endsBeforeItStarts(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(\sprintf(
            'A duration cannot end at %s, before its start at %s (RN-09).',
            $to->format('Y-m-d\TH:i:s.uP'),
            $from->format('Y-m-d\TH:i:s.uP'),
        ));
    }
}
