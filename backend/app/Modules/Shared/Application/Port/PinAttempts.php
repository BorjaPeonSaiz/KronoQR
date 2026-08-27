<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Bloqueo por intentos fallidos del PIN del empleado (RS-12, RF-ID-06).
 *
 * **Por que vive en `Shared` y no en el modulo que lo consume.** Lo tocan tres
 * partes que no pueden importarse entre si (doc 02 §1.6): `Workforce` lo
 * **limpia** al restablecer el PIN (RF-ID-09, tarea 1.13), el fichaje de
 * respaldo del quiosco lo **incrementa** al fallar (RF-AT-11, tarea 1.12) y el
 * acceso al portal hace lo mismo (RF-ID-06, tarea 1.11). Un puerto por modulo
 * daria tres contadores distintos, y entonces «restablecer desbloquea»
 * dependeria de por cual de las tres puertas se estuviera fallando.
 *
 * **No es el mismo bloqueo que el del panel.** `Identity\Application\Port\
 * LoginAttempts` cuenta fallos de contrasena de una cuenta de gestion; este
 * cuenta fallos de PIN de un empleado. Comparten forma y no deben compartir
 * contador: quien teclea mal en un quiosco a las 06:00 no es quien prueba
 * contrasenas contra el panel, y sus umbrales son configuracion distinta
 * (`IDENTITY_PIN_MAX_ATTEMPTS`).
 *
 * **Tampoco es el limite de peticiones del §7.1**, que cuenta peticiones por
 * origen. Este cuenta fallos por **empleado**, que es lo que frena a quien
 * prueba PIN contra un codigo conocido desde varios quioscos.
 *
 * La clave es siempre el `employee_uuid` (regla dura 21): nunca el codigo de
 * empleado, que va impreso en la tarjeta, ni nada que identifique a la persona
 * por su nombre.
 */
interface PinAttempts
{
    /**
     * Si el PIN de este empleado esta bloqueado ahora mismo.
     *
     * Se comprueba **antes** de verificar el PIN: si no, el bloqueo seria un
     * oraculo que confirma cuando se acierta.
     */
    public function isLocked(string $employeeUuid): bool;

    /**
     * Segundos que faltan para el desbloqueo. Cero si no esta bloqueado.
     */
    public function secondsUntilUnlock(string $employeeUuid): int;

    /**
     * Anota un fallo y, alcanzado el umbral, bloquea.
     */
    public function recordFailure(string $employeeUuid): void;

    /**
     * Borra el contador: acierto, o PIN restablecido.
     *
     * RF-ID-09 lo exige en el restablecimiento. Un empleado bloqueado que pide
     * un PIN nuevo tiene que poder usarlo **en el momento**; si no, la unica
     * salida sera esperar quince minutos delante del quiosco, y la regla dura 19
     * dice que el quiosco no bloquea al empleado.
     */
    public function clear(string $employeeUuid): void;
}
