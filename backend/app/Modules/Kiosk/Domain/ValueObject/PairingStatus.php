<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * En que punto de los tres pasos esta una solicitud de emparejamiento
 * (**RF-PD-06**, tarea 5.6).
 *
 * `pending` → `confirmed` → `claimed`, y solo en ese orden. Cada transicion la
 * hace un actor distinto: la tablet crea, el administrador confirma y la tablet
 * recoge.
 *
 * ## «Caducada» NO esta aqui, y es la decision que sostiene el resto
 *
 * La caducidad se **deriva** de `expires_at` frente al instante que recibe el
 * agregado (regla dura 2: el dominio no lee el reloj). Un cuarto caso almacenado
 * necesitaria un proceso que lo mantuviera al dia, y el dia que ese proceso no
 * corriera el sistema creeria que una solicitud de hace una hora sigue viva —que
 * es exactamente el fallo que la caducidad existe para evitar—. Lo derivable se
 * deriva.
 *
 * ## «Rechazada» tampoco
 *
 * Un `claim` con el secreto equivocado o un `confirm` con un codigo que no existe
 * **no cambian nada**: no consumen la solicitud, no la caducan y no cuentan
 * intentos. Si el rechazo fuera un estado, bastaria teclear mal un codigo tres
 * veces para dejar sin emparejar una tablet que estaba perfectamente (regla dura
 * 19).
 */
enum PairingStatus: string
{
    /** Creada por la tablet. Nadie ha tecleado el codigo todavia. */
    case Pending = 'pending';

    /** Un `admin` tecleo el codigo: la fila de `devices` ya existe y esta activa. */
    case Confirmed = 'confirmed';

    /** La tablet recogio su token. **Una sola vez**: aqui se acaba el camino. */
    case Claimed = 'claimed';
}
