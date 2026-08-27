<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * Bloqueo por intentos fallidos de acceso al panel (RF-ID-01).
 *
 * **No es lo mismo que el limite de peticiones del borde.** El `throttle` de
 * Nginx y el de la ruta cuentan peticiones por origen (§7.1); esto cuenta
 * fallos por **cuenta**, que es lo que frena a quien prueba contrasenas contra
 * un correo conocido desde muchas IP. Sin la segunda mitad, cinco peticiones por
 * minuto durante una noche siguen siendo miles de intentos.
 *
 * El contador se guarda fuera del proceso —Redis en produccion— porque hay
 * varios trabajadores PHP y un contador en memoria no cuenta nada.
 */
interface LoginAttempts
{
    /**
     * Si la clave esta bloqueada ahora mismo.
     *
     * Se comprueba **antes** de mirar la contrasena, y por eso un bloqueo activo
     * responde igual aunque la contrasena sea correcta: si no, el bloqueo seria
     * un oraculo que confirma cuando se acierta.
     */
    public function isLocked(string $key): bool;

    /**
     * Segundos que faltan para que la clave se desbloquee. Cero si no lo esta.
     *
     * Es lo que viaja en `Retry-After`. Se da el dato porque el cliente legitimo
     * —una persona que se ha equivocado— necesita saber cuanto esperar, y no
     * revela nada que quien ataca no pueda medir con un reloj.
     */
    public function secondsUntilUnlock(string $key): int;

    /**
     * Anota un fallo y, si se alcanza el umbral, bloquea la clave.
     */
    public function recordFailure(string $key): void;

    /**
     * Borra el contador tras un acceso correcto.
     */
    public function clear(string $key): void;
}
