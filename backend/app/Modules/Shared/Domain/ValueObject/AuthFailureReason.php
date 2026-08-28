<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Por que no se autentico alguien. **Solo para el servidor** (RS-03, regla dura
 * 17).
 *
 * Este valor viaja al log estructurado y a ningun sitio mas: nunca a la
 * respuesta HTTP, nunca a la metrica —donde seria una etiqueta que un panel
 * publico enseñaria— y nunca al asiento de `audit_log`. Hacia fuera, los
 * rechazos siguen siendo uno solo y del mismo coste.
 *
 * **La lista es corta a proposito.** No distingue «ese codigo no existe» de «ese
 * PIN no es» ni «ese correo no existe» de «esa contrasena no es», porque el
 * servidor **tampoco tiene el dato a mano** en el camino que produce la
 * respuesta: los verificadores devuelven un unico rechazo justamente para que no
 * haya ninguna rama que los separe. Inventar aqui dos motivos obligaria a
 * crearla.
 */
enum AuthFailureReason: string
{
    /**
     * La credencial no valia, por la causa que sea: no existe el sujeto, la
     * contrasena o el PIN no coinciden, o la persona no esta de alta (RN-14).
     */
    case INVALID_CREDENTIALS = 'invalid_credentials';

    /**
     * Habia un bloqueo activo, asi que la credencial **ni se comprobo** (RS-12).
     *
     * **Solo se emite donde la respuesta ya distingue el bloqueo**: el panel, que
     * contesta `429` con `Retry-After`. En el PIN —portal y quiosco— los cinco
     * rechazos son la misma respuesta y el apunte es `invalid_credentials` en
     * todos, bloqueo incluido: un log que separase «existe y esta bloqueado» de
     * «no existe» reconstruiria dentro del servidor el oraculo que RS-03 evita
     * hacia fuera, y ese log viaja al fabricante (ADR-020). Ahi el bloqueo se ve
     * donde tiene que verse, en el asiento `auth.lockout_started` (ADR-039).
     */
    case LOCKED = 'locked';

    /**
     * El sobre cerrado con el que el quiosco protege el PIN no abrio (RF-AT-11,
     * RL-12). No cuenta como intento fallido del empleado —un criptograma
     * corrupto no dice nada del PIN que lleva dentro— y por eso es un motivo
     * propio y no `invalid_credentials`.
     */
    case SEALED_PIN_UNREADABLE = 'sealed_pin_unreadable';

    /**
     * La credencial era buena y aun asi no hubo sesion: la persona dejo de
     * existir, o dejo de tener centro, entre la comprobacion y la emision.
     */
    case SESSION_NOT_ISSUED = 'session_not_issued';
}
