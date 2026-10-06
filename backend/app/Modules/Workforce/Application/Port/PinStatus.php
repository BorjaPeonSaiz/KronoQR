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
 * `pending` es el estado de **toda persona importada** (RF-GP-05): la
 * importacion masiva no emite PIN, porque un PIN se muestra una sola vez y se
 * entrega en mano con la tarjeta; RRHH lo emite desde la ficha en ese momento.
 * Tambien lo tienen las fichas anteriores a RF-ID-09. El alta individual nunca
 * lo deja: emite en su misma transaccion (tarea 1.13). Es el estado por el que
 * filtra «Sin emitir» en el listado, y por eso tiene que ser verdad: mostrar
 * «emitido» a alguien que no lo tiene esconderia a quien no puede fichar por
 * respaldo ni entrar al portal.
 */
enum PinStatus: string
{
    case Pending = 'pending';

    case Issued = 'issued';

    case Delivered = 'delivered';
}
