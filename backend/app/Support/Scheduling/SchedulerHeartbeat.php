<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

/**
 * El latido del planificador: un fichero cuya hora de modificacion dice cuando
 * se ejecuto por ultima vez el bucle de `schedule:work` (bloque 9 de la 2.3.0,
 * O1).
 *
 * ## Por que existe
 *
 * `schedule:work` es un proceso que puede seguir vivo con el bucle colgado: sin
 * el, no hay copia nocturna, ni verificacion de la cadena de auditoria, ni
 * conciliacion del registro, ni purgas, y el contenedor sigue figurando
 * «running». El `healthcheck` del servicio `scheduler` mira la edad de este
 * fichero (`find … -mmin -3`), de modo que un bucle que lleva tres minutos sin
 * tocarlo pasa a `unhealthy` y lo ven `docker compose ps` y `doctor.sh`.
 *
 * ## Un fichero y no una metrica
 *
 * La comprobacion del contenedor tiene que ser barata (no arrancar la aplicacion
 * ni hablar con Redis) y no depender de nada que pueda estar caido: es
 * precisamente la sonda de «el planificador esta vivo», no la de «Redis esta
 * vivo». `MedicionDeWalAusente` ya vigila desde Prometheus que el planificador
 * publica su medida cada minuto; esto es lo mismo a nivel de contenedor.
 *
 * ## El fichero
 *
 * `/tmp`, que existe y es escribible para el usuario del contenedor, y que
 * desaparece al recrearlo (no hay latido «heredado» de un contenedor anterior).
 * El nombre tambien esta escrito en el `healthcheck` de `compose.*.yaml` y en el
 * `entrypoint.sh` de la imagen PHP, que lo toca al arrancar para que el servicio
 * no espere un minuto entero a su primer latido; una prueba de arquitectura ata
 * las tres copias.
 *
 * Nunca lanza: un latido que no se puede escribir (disco lleno) se nota por el
 * `healthcheck`, no por una excepcion que detendria el resto de tareas del minuto.
 */
final class SchedulerHeartbeat
{
    public const string PATH = '/tmp/kronoqr-scheduler-heartbeat';

    public function __invoke(): void
    {
        $this->beat();
    }

    public function beat(string $path = self::PATH): bool
    {
        $directory = dirname($path);

        return is_dir($directory) && is_writable($directory) && touch($path);
    }
}
