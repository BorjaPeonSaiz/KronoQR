<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PinOrigin;

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
 * prueba PIN contra un codigo conocido desde varios quioscos. Los dos controles
 * conviven y ninguno sustituye al otro: RS-12 los enumera juntos.
 *
 * ## Por que la clave lleva el origen, y por que ya
 *
 * El §7.5 exige que el bloqueo sea **«por empleado y por origen»**, y aqui es
 * donde esa frase se cumple o no se cumple. Con un contador unico por persona,
 * quien sondease el PIN de alguien contra el portal —accesible desde la red
 * interna del hotel, RF-ID-08— dejaria a esa persona sin poder fichar en el
 * quiosco a la manana siguiente: un ataque a una puerta cerraria la otra, que es
 * la regla dura 19 provocada desde fuera. Separar los contadores hace que cada
 * puerta se defienda sola y que un compromiso no escale.
 *
 * `PinOrigin` nace con sus dos casos aunque hoy solo fiche el quiosco. Es
 * deliberado: la tarea 1.11 reutiliza este mismo mecanismo pasando
 * `PinOrigin::PORTAL` y **no tiene que volver a tocar ni el puerto, ni la cache,
 * ni la prueba de escalones**. Anadir el parametro despues habria significado
 * cambiar la firma con contadores vivos en produccion, y el efecto de eso es
 * desbloquear a todo el mundo el dia del despliegue.
 *
 * La clave es siempre el `employee_uuid` (regla dura 21): nunca el codigo de
 * empleado, que va impreso en la tarjeta, ni nada que identifique a la persona
 * por su nombre.
 */
interface PinAttempts
{
    /**
     * Si el PIN de este empleado esta bloqueado ahora mismo **por esta puerta**.
     *
     * Se comprueba **antes** de verificar el PIN: si no, el bloqueo seria un
     * oraculo que confirma cuando se acierta.
     */
    public function isLocked(string $employeeUuid, PinOrigin $origin): bool;

    /**
     * Segundos que faltan para el desbloqueo. Cero si no esta bloqueado.
     */
    public function secondsUntilUnlock(string $employeeUuid, PinOrigin $origin): int;

    /**
     * Anota un fallo y, alcanzado un escalon, bloquea (doc 02 §7.5).
     *
     * El escalon lo decide `Shared\Domain\Policy\PinLockoutPolicy` con los
     * umbrales ya resueltos de la configuracion. Aqui solo se registra el hecho.
     */
    public function recordFailure(string $employeeUuid, PinOrigin $origin): void;

    /**
     * Borra el contador de **todas** las puertas: acierto, o PIN restablecido.
     *
     * **Sin parametro de origen, y no es un olvido.** Los dos usos que tiene son
     * los dos que no admiten matiz: al acertar, el castigo acumulado deja de
     * tener sentido; y al restablecer, el PIN anterior deja de existir —la unica
     * copia era el hash— asi que ningun contador levantado contra el describe ya
     * nada. Poder limpiar una sola puerta invitaria a restablecer el PIN y dejar
     * a alguien bloqueado en la otra.
     *
     * RF-ID-09 lo exige en el restablecimiento. Un empleado bloqueado que pide
     * un PIN nuevo tiene que poder usarlo **en el momento**; si no, la unica
     * salida sera esperar quince minutos delante del quiosco, y la regla dura 19
     * dice que el quiosco no bloquea al empleado.
     */
    public function clear(string $employeeUuid): void;
}
