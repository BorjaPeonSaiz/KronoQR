<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\ConnectionResolverInterface as Connections;
use Throwable;

/**
 * Las dependencias que necesita una peticion real, comprobadas de verdad.
 *
 * Es lo que responde `GET /api/v1/ready`, la sonda que gobierna el despliegue
 * sin parada (RNF-D-04) y la comprobacion posterior a una actualizacion
 * (RF-PD-10). Si esto dice que no, el orquestador no manda trafico a esta
 * instancia.
 *
 * ## Que se comprueba y por que solo eso
 *
 * PostgreSQL y Redis, que son las dos piezas sin las cuales una peticion del
 * producto no puede terminar: el registro horario vive en la primera y los
 * limites de peticiones, los contadores de intentos y las metricas en la
 * segunda. Se comprueban con la operacion mas barata que **abre la conexion de
 * verdad** —`select 1` y `PING`—, no leyendo configuracion: una sonda que solo
 * mira que el host este escrito en el `.env` da verde con la base de datos
 * apagada.
 *
 * No se comprueba el disco, ni el correo, ni los certificados. Eso es la
 * comprobacion de salud posinstalacion (RF-PD-13), que es una accion
 * autenticada del administrador y puede permitirse tardar; esta la ejecuta un
 * orquestador cada pocos segundos.
 *
 * ## Se para en el primer fallo
 *
 * El desenlace es el mismo con una dependencia caida que con las dos —`503`,
 * sin decir cual—, asi que seguir preguntando solo anade latencia a una
 * instalacion que ya esta en problemas.
 *
 * ## No es la sonda de vida
 *
 * `GET /api/v1/health` no pasa por aqui a proposito: una sonda de vida que toca
 * dependencias reinicia el contenedor de PHP cuando lo que esta caido es
 * PostgreSQL, que es exactamente el fallo que no se quiere.
 */
final readonly class DependencyProbe
{
    public function __construct(
        private Connections $connections,
        private Redis $redis,
    ) {}

    public function firstFailure(): ?DependencyFailure
    {
        try {
            $this->connections->connection()->select('select 1');
        } catch (Throwable $exception) {
            return new DependencyFailure('database', $exception->getMessage());
        }

        try {
            $this->redis->connection()->command('PING', []);
        } catch (Throwable $exception) {
            return new DependencyFailure('redis', $exception->getMessage());
        }

        return null;
    }
}
