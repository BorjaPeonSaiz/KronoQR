<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Pin;

use App\Modules\Workforce\Application\Port\PinPolicy;
use App\Modules\Workforce\Application\Port\PinPolicyProvider;
use Random\RandomException;
use RuntimeException;

/**
 * Genera el PIN de 6 digitos (RF-ID-09).
 *
 * **`random_int` y nunca `rand` ni `mt_rand`.** Mersenne Twister es predecible:
 * observando unas cuantas salidas se reconstruye su estado y, con el, todas las
 * siguientes. Aqui la salida es la llave del registro horario personal de una
 * persona (RL-05) y del fichaje de respaldo (RF-AT-11), asi que la fuente tiene
 * que ser la del sistema operativo. `random_int` lanza `\Random\RandomException`
 * si no hay entropia disponible, y eso es lo correcto: es preferible no dar de
 * alta a alguien que darle un PIN adivinable.
 *
 * **Por que se rechazan los patrones triviales.** Un espacio de 10^6 con los
 * tres primeros intentos evidentes no es un espacio de 10^6: con cinco intentos
 * antes del bloqueo (RS-12), `000000`, `123456` y `111111` cubren una parte
 * desproporcionada de lo que la gente acepta sin cambiar. La lista concreta es
 * **configuracion** y entra por {@see PinPolicyProvider} (regla dura 13).
 *
 * **Rechazo y reintento, no correccion.** Un PIN prohibido se descarta y se
 * genera otro; no se «arregla» sumandole uno ni cambiandole un digito, porque
 * cualquier correccion determinista concentra probabilidad en los vecinos de los
 * valores excluidos y estrecha el espacio justo donde se acaba de decir que no
 * se quiere estar.
 *
 * **Sin estado y sin reloj**: dos llamadas seguidas no tienen relacion entre si.
 */
final readonly class PinGenerator
{
    /**
     * Con 22 valores excluidos de 10^6, la probabilidad de encadenar 20 rechazos
     * es de 1 entre 10^70. El limite no es una expectativa: existe para que una
     * configuracion absurda —alguien que excluyera el millon de PIN posibles—
     * falle con un error legible en vez de colgar el proceso.
     */
    private const int MAX_ATTEMPTS = 20;

    public function __construct(private PinPolicyProvider $policies) {}

    /**
     * @throws RandomException si el sistema no puede dar aleatoriedad
     */
    public function generate(): string
    {
        $policy = $this->policies->policy();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $pin = $this->sixDigits();

            if (! $policy->forbids($pin)) {
                return $pin;
            }
        }

        throw new RuntimeException(
            'No se ha podido generar un PIN admisible en '.self::MAX_ATTEMPTS.' intentos: revisa la '
            .'lista de PIN excluidos de la instalacion (identity.pin.forbidden).'
        );
    }

    /**
     * Seis digitos con ceros a la izquierda: `000042` es tan valido como
     * `483920` y excluirlo dejaria fuera el 10 % del espacio.
     *
     * @throws RandomException
     */
    private function sixDigits(): string
    {
        $maximum = 10 ** PinPolicy::LENGTH - 1;

        return str_pad((string) random_int(0, $maximum), PinPolicy::LENGTH, '0', STR_PAD_LEFT);
    }
}
