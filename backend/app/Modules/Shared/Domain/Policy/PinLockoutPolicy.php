<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Policy;

use InvalidArgumentException;

/**
 * El bloqueo creciente del PIN, en su forma pura (RS-12, doc 02 §7.5).
 *
 * Responde a una sola pregunta —«con estos fallos acumulados, ¿cuantos segundos
 * de bloqueo?»— y la responde sin cache, sin reloj y sin configuracion. Es lo
 * que hace que los tres escalones se puedan probar con los numeros exactos en
 * una prueba unitaria de milisegundos, en vez de a traves de un adaptador que
 * necesita Redis para decir si 3 fallos son 5 minutos.
 *
 * ## Los tres escalones y por que son tres
 *
 * | Fallos acumulados | Bloqueo |
 * |---|---|
 * | 3 | 5 min |
 * | 5 | 15 min |
 * | 10 | 60 min |
 *
 * Escalado aproximadamente geometrico: cada escalon triplica al anterior. Con
 * eso, barrer un espacio de 10^6 es inviable —tres intentos por cada hora de la
 * decima en adelante— y a quien se equivoca una vez no se le castiga como a
 * quien esta probando. El equilibrio es el que pide el plan y esta fijado como
 * **decision de producto**, no como medicion ni como requisito legal: si la
 * operacion real de un cliente aconseja otro punto, se mueve la configuracion.
 * Lo que no es negociable es que los seis numeros sean configuracion y no
 * constantes (regla dura 13, ADR-017), y por eso entran por el constructor ya
 * resueltos (regla dura 14: el dominio no consulta la configuracion, la recibe).
 *
 * ## El castigo nunca baja al subir los fallos
 *
 * Los escalones se evaluan de mayor a menor y se toma el **maximo** de los que
 * apliquen. Con la configuracion de serie da igual, pero una instalacion puede
 * escribir `TIER3_SECONDS` mas bajo que `TIER2_SECONDS` por error, y entonces el
 * decimo fallo saldria mas barato que el quinto: exactamente el incentivo
 * contrario al que este objeto existe para crear.
 *
 * ## Lo que NO decide
 *
 * No sabe cuando ocurrieron los fallos ni cuando caducan: eso es de quien lleva
 * la cuenta. Aqui solo se traduce «cuantos» en «cuanto», y se publica la ventana
 * de olvido para que quien la aplica no tenga que reinventar el numero.
 */
final readonly class PinLockoutPolicy
{
    /**
     * @param  int  $tier1Attempts  Fallos del primer escalon (`IDENTITY_PIN_MAX_ATTEMPTS`).
     * @param  int  $tier1Seconds  Bloqueo del primer escalon (`IDENTITY_PIN_LOCKOUT_SECONDS`).
     * @param  int  $resetSeconds  Ventana de olvido: sin fallos durante este tiempo, el
     *                             contador vuelve a cero (`IDENTITY_PIN_LOCKOUT_RESET_HOURS`).
     */
    public function __construct(
        private int $tier1Attempts,
        private int $tier1Seconds,
        private int $tier2Attempts,
        private int $tier2Seconds,
        private int $tier3Attempts,
        private int $tier3Seconds,
        private int $resetSeconds,
    ) {
        foreach ([$tier1Attempts, $tier2Attempts, $tier3Attempts] as $attempts) {
            if ($attempts < 1) {
                throw new InvalidArgumentException('Un escalon de bloqueo necesita al menos un fallo.');
            }
        }

        foreach ([$tier1Seconds, $tier2Seconds, $tier3Seconds, $resetSeconds] as $seconds) {
            if ($seconds < 1) {
                throw new InvalidArgumentException('Un escalon de bloqueo necesita una duracion positiva.');
            }
        }
    }

    /**
     * Segundos de bloqueo que corresponden a estos fallos acumulados. Cero si
     * todavia no se alcanza el primer escalon.
     */
    public function lockSecondsFor(int $failures): int
    {
        $seconds = 0;

        if ($failures >= $this->tier3Attempts) {
            $seconds = max($seconds, $this->tier3Seconds);
        }

        if ($failures >= $this->tier2Attempts) {
            $seconds = max($seconds, $this->tier2Seconds);
        }

        if ($failures >= $this->tier1Attempts) {
            $seconds = max($seconds, $this->tier1Seconds);
        }

        return $seconds;
    }

    /**
     * Cuanto tiempo sin fallos hace falta para que el contador vuelva a cero.
     *
     * Es una ventana **deslizante**: la cuenta arranca en el ultimo fallo, no en
     * el primero. Si arrancara en el primero, quien fallara una vez cada
     * veintitres horas nunca acumularia nada y el escalon mas alto seria
     * inalcanzable para justo el patron que existe para frenar.
     */
    public function resetSeconds(): int
    {
        return $this->resetSeconds;
    }

    /**
     * Cuantos fallos merece la pena recordar.
     *
     * Por encima del ultimo escalon el bloqueo ya no crece, asi que guardar mas
     * marcas solo haria mas grande la entrada de cache sin cambiar ninguna
     * respuesta. Se deja un margen sobre el umbral para que la cifra que se
     * registra siga siendo util al diagnosticar.
     */
    public function trackedFailures(): int
    {
        return $this->tier3Attempts * 2;
    }
}
