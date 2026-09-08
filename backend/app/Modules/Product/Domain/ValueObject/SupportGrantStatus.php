<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * En que estado esta una concesion de soporte **en un instante dado**
 * (RF-PD-11, esquema `SupportGrant` del contrato).
 *
 * ## No es una columna, y no debe serlo
 *
 * No hay `status` en `support_grants`: se **calcula** con el reloj del momento
 * en que se pregunta. Una columna obligaria a que algo la actualizara al caducar
 * —una tarea programada—, y una concesion caducada seguiria constando activa
 * hasta que esa tarea corriera. RF-PD-11 pide caducidad **efectiva**: el acceso
 * deja de funcionar sin que nadie haga nada, y el estado que se enseña tiene que
 * decir lo mismo que hace el token.
 *
 * ## `Revoked` gana a `Expired`
 *
 * Una concesion revocada antes de caducar consta como revocada para siempre, y
 * no pasa a «caducada» cuando llega su fecha. Lo que describe este campo es **el
 * hecho que retiro el acceso**, y ese hecho fue una persona pulsando un boton:
 * borrarlo al pasar la fecha dejaria sin rastro la unica accion deliberada de
 * las dos.
 */
enum SupportGrantStatus: string
{
    /** Vigente: no revocada y con `expires_at` en el futuro. El token autentica. */
    case Active = 'active';

    /** Caduco sola. Nadie hizo nada, que es exactamente el diseño (RF-PD-11). */
    case Expired = 'expired';

    /** Alguien la retiro antes de tiempo. Gana a `Expired`. */
    case Revoked = 'revoked';
}
