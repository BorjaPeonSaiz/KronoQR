<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\OffboardedPinHolder;
use DateTimeImmutable;
use DateTimeZone;

/**
 * **¿Era de su periodo el PIN correcto de una persona de baja?** (RN-20, D15,
 * enmienda del 29-10-2026 de ADR-043; ADR-062 §8).
 *
 * El PIN correcto de una persona de baja se rechaza siempre igual (RS-03). La
 * fila se atribuye a esa persona y se marca para revision **solo si la fecha
 * civil del `occurred_at`, en la zona del centro, cae entre la fecha de alta y
 * la de cese del periodo que cerro la baja, las dos incluidas**. Es el fichaje
 * del ultimo dia, encolado sin red, que llega despues de registrarse la baja.
 *
 * - **Despues del cese**, no: no hay jornada que revisar, como con la tarjeta
 *   posterior a su retirada (RN-20).
 * - **Antes del alta**, tampoco, y por lo mismo que la emision acota la
 *   tarjeta: el `occurred_at` lo pone la tablet, y sin cota inferior quien
 *   conozca el PIN podria sembrar una incidencia por cada fecha que eligiera.
 *   Un periodo anterior a una readmision o el hueco entre dos periodos caen
 *   aqui: el periodo que cuenta es el de la proyeccion de la ficha.
 *
 * Compara **fechas civiles**, no instantes: para el PIN no hay instante de
 * retirada de una tarjeta. El turno de noche del cese que sale a las 06:00 del
 * dia siguiente no entra: su salida tiene fecha civil posterior al cese. Es lo
 * que fija ADR-043 en su enmienda («la fecha civil del `occurred_at`»).
 *
 * Pura: no conoce el reloj (regla dura 2) ni consulta la zona; la recibe con la
 * persona.
 */
final readonly class OffboardedPinPolicy
{
    public function attributes(DateTimeImmutable $occurredAt, OffboardedPinHolder $holder): bool
    {
        $civilDate = WorkDate::fromInstant($occurredAt->setTimezone(new DateTimeZone('UTC')), $holder->timezone)->isoDate;

        return $holder->hiredOn <= $civilDate && $civilDate <= $holder->terminatedOn;
    }
}
