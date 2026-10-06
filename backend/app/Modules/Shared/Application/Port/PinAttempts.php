<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PinAttemptReservation;
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
 * ## Por que la clave lleva el canal, y por que ya
 *
 * El §7.5 exige que el bloqueo sea **«por empleado y por canal»**, y aqui es
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
     * Solo lectura, para consultar el estado. El camino del PIN no pregunta con
     * esto: reserva con {@see self::reserve()}, que lee y anota a la vez.
     */
    public function isLocked(string $employeeUuid, PinOrigin $origin): bool;

    /**
     * Segundos que faltan para el desbloqueo. Cero si no esta bloqueado.
     */
    public function secondsUntilUnlock(string $employeeUuid, PinOrigin $origin): int;

    /**
     * Reserva un intento **antes de comparar el PIN**: lee el bloqueo y, si no
     * lo hay, anota ya el intento como fallo (doc 02 §7.5, ADR-050).
     *
     * El escalon lo decide `Shared\Domain\Policy\PinLockoutPolicy` con los
     * umbrales ya resueltos de la configuracion. Aqui solo se registra el hecho.
     *
     * **Por que antes y no despues.** Anotando el fallo despues de comparar,
     * todos los intentos que llegaban a la vez pasaban la comprobacion del
     * bloqueo antes de que ninguno contara, y se comparaban contra el PIN real:
     * de 4 a 22 de 25 en una rafaga (`PinLockoutConcurrencyTest`, 06-10-2026). La
     * cota por empleado dependia del tamaño de la botnet. Reservando con el
     * candado del empleado y la puerta cogido, solo llegan a compararse los
     * intentos que caben antes del primer escalon.
     *
     * **Atomico frente a otros intentos del mismo empleado y la misma puerta**:
     * leer, decidir y anotar no admite que otro proceso se meta en medio. Si el
     * candado no se consigue, se reserva sin el (regla dura 19): un intento de
     * mas o de menos en una avalancha, nunca un `500`.
     *
     * **Bloqueado no anota**: el bloqueo de quien ya lo tiene no crece por
     * insistir (RS-12). Las dos ramas hacen el mismo trabajo contra la cache
     * —leer y escribir—, de modo que el coste no dice si habia bloqueo (RS-03).
     *
     * **Si el PIN resulta ser el bueno**, quien llama invoca {@see self::clear()},
     * que borra la cuenta entera y con ella la marca reservada: el acierto no
     * queda contado como fallo. Sin reabrir la carrera: `clear()` toma el mismo
     * candado, y una reserva simultanea o queda antes —y se borra con el resto
     * del castigo del PIN que acaba de acertarse— o despues, y cuenta.
     *
     * **El codigo tecleado solo ordena el señuelo.** Cuando no hay empleado, el
     * candado del señuelo es uno por codigo y puerta, para que una rafaga con un
     * mismo codigo espere lo mismo exista o no (RS-03, regla dura 17). Con
     * empleado no se usa: su candado va por `employee_uuid`.
     *
     * @param  string  $employeeCode  El codigo tal como se tecleo.
     * @param  string|null  $employeeUuid  `null` si no hay nadie con ese codigo: se reserva contra
     *                                     el señuelo, que paga el mismo trabajo y no bloquea a nadie.
     */
    public function reserve(string $employeeCode, ?string $employeeUuid, PinOrigin $origin): PinAttemptReservation;

    /**
     * Borra el contador de **todas** las puertas: acierto, o PIN restablecido.
     *
     * **Sin parametro de canal, y no es un olvido.** Los dos usos que tiene son
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
