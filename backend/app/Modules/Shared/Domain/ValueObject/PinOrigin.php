<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Por que puerta se esta tecleando el PIN (RS-12, doc 02 §7.5).
 *
 * **Existe porque el §7.5 exige que el bloqueo sea «por empleado y por
 * origen»**, y sin este tipo esa frase no tendria como escribirse. Un contador
 * unico por persona convertiria las dos puertas en una sola, y eso tiene una
 * consecuencia concreta y mala: quien sondease el portal de alguien —expuesto a
 * la red interna del hotel, RF-ID-08— dejaria a esa persona sin poder fichar en
 * el quiosco a la manana siguiente. Es la regla dura 19 al reves, provocada
 * desde fuera y sin tocar el quiosco.
 *
 * Al reves tambien importa: quien se equivoca tres veces con guantes puestos a
 * las 06:00 delante de una tablet no es quien prueba PIN contra el portal desde
 * un navegador. Son dos poblaciones, dos ritmos y dos riesgos, y merecen dos
 * contadores.
 *
 * **Nace con los dos casos aunque hoy solo se use uno.** El quiosco es la tarea
 * 1.12 y el portal la 1.11; que el enum este completo desde el principio es lo
 * que permite que la 1.11 reutilice el mecanismo entero sin volver a tocar el
 * puerto, la cache ni la prueba de escalones.
 *
 * Los valores respaldados forman parte de la **clave de cache**, no de ninguna
 * columna: cambiarlos no migra nada, pero reinicia los contadores vivos.
 */
enum PinOrigin: string
{
    /** Fichaje de respaldo en el quiosco cuando falta la tarjeta (RF-AT-11). */
    case KIOSK = 'kiosk';

    /** Acceso del empleado a su propio registro horario (RF-ID-06, RL-05). */
    case PORTAL = 'portal';
}
