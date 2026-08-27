<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * En que punto de su ciclo esta el PIN de una persona (RF-ID-09).
 *
 * **Es el estado, nunca el valor.** Es lo unico del PIN que sale de la
 * instalacion despues de emitirlo: el panel necesita saber a quien le falta
 * recibirlo, y eso no exige conocer ningun PIN.
 *
 * `pending` solo aparece en fichas anteriores a RF-ID-09 —el alta emite el PIN
 * en la misma transaccion (tarea 1.13)— y en las que un dia lo pierdan por una
 * migracion. Se mantiene en el catalogo porque es un estado real de la tabla:
 * fingir que no existe haria que el panel mostrara «emitido» a alguien que no
 * puede entrar al portal.
 */
enum PinStatus: string
{
    case Pending = 'pending';

    case Issued = 'issued';

    case Delivered = 'delivered';
}
