<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use App\Modules\Shared\Domain\Support\UtcInstant;

/**
 * Cuantos decimales de segundo lleva un instante escrito por {@see UtcInstant}.
 *
 * El esquema `UtcTimestamp` del contrato admite de cero a seis, y cada campo
 * tiene los suyos desde que se publico: `shift_entries` guarda microsegundos y
 * una respuesta redondeada no seria la hora escrita; el quiosco recibe
 * milisegundos porque es lo que entiende `Date` en cualquier navegador; una
 * caducidad de sesion se escribe al segundo. Cambiar la precision de un campo
 * publicado cambia el contrato que ven los tres frontends, asi que se elige
 * aqui, de forma explicita, y no se uniforma de rebote.
 *
 * El valor es el patron de `DateTimeInterface::format()`: la `Z` es literal y
 * por eso **solo** {@see UtcInstant} lo usa, despues de pasar a UTC.
 */
enum UtcPrecision: string
{
    case Microseconds = 'Y-m-d\TH:i:s.u\Z';
    case Milliseconds = 'Y-m-d\TH:i:s.v\Z';
    case Seconds = 'Y-m-d\TH:i:s\Z';
}
