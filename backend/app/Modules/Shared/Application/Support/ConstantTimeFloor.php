<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

/**
 * Suelo de duracion para un camino de rechazo (RS-03, regla dura 17).
 *
 * ## Que problema resuelve
 *
 * Dos rechazos que ejecutan el mismo trabajo **no tardan lo mismo**: un acierto
 * de indice y un fallo de indice se separan por microsegundos, y con suficientes
 * muestras esa diferencia se mide a traves de la red. Igualar el trabajo es la
 * mitad del control —y la que hay que hacer primero, porque esta clase no la
 * sustituye—; el suelo es la otra mitad: espera hasta un minimo comun, de modo
 * que la varianza que quede caiga por debajo del ruido.
 *
 * ## Se aplica SOLO al rechazo, y es deliberado
 *
 * Un camino aceptado hace mas cosas —emite un token, escribe en `audit_log`— y
 * fingir que tarda lo mismo obligaria a un suelo tan alto que se notaria en el
 * cambio de turno. Lo que RS-03 exige es que los **rechazos** sean
 * indistinguibles **entre si**, no que un rechazo parezca un acierto: eso ya lo
 * dice el codigo de respuesta.
 *
 * ## Un solo sitio, y por eso vive en Shared
 *
 * Lo usan el resolutor de credenciales del fichaje (`HmacSignatureVerifier`) y la
 * recogida del emparejamiento (`ClaimPairing`), que son dos modulos distintos con
 * la misma obligacion. Con una copia en cada uno, la segunda se habria escrito
 * mirando la primera y las dos habrian divergido en la primera correccion — y una
 * de las dos habria dejado de proteger sin que nada fallara.
 *
 * **El umbral es uno solo** (`security.rejection_floor_ms`): dos numeros para el
 * mismo control acaban con uno de ellos a cero por descuido.
 *
 * ## PHP puro, sin Illuminate
 *
 * No hay nada de framework aqui, y por eso vive en `Application/Support` y se
 * puede probar sin arrancar nada. El valor de configuracion se lo pasa quien la
 * construye (regla dura 14 aplicada a la configuracion en general).
 */
final readonly class ConstantTimeFloor
{
    public function __construct(
        /** Duracion minima de un rechazo, en milisegundos. A cero se desactiva. */
        private int $floorMs,
    ) {}

    /**
     * El instante en el que empieza a contar el camino.
     *
     * **`hrtime()` y no `microtime()`**: es monotono. `microtime()` puede
     * retroceder con un ajuste de NTP y dejar la espera decidiendo sobre un
     * numero negativo, justo en el control que existe para que no haya sorpresas.
     */
    public function startedAt(): float|int
    {
        return hrtime(true);
    }

    /**
     * Espera lo que falte para llegar al suelo. Si el trabajo ya tardo mas, no
     * duerme nada: el suelo iguala por abajo, no alarga por sistema.
     */
    public function padTo(float|int $startedAt): void
    {
        $floorNs = max(0, $this->floorMs) * 1_000_000;
        $elapsedNs = hrtime(true) - $startedAt;

        if ($elapsedNs >= $floorNs) {
            return;
        }

        usleep((int) (($floorNs - $elapsedNs) / 1_000));
    }
}
