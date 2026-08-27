<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use Illuminate\Contracts\Redis\Factory;
use RuntimeException;

/**
 * Un Redis que no responde, sin apagar el de verdad.
 *
 * Se enlaza en el contenedor —`app()->instance(Factory::class, new UnavailableRedis)`—
 * para probar las dos mitades de la promesa de las sondas: que
 * `GET /api/v1/ready` responde `503` cuando una dependencia se cae, y que
 * `GET /api/v1/health` sigue respondiendo `200` cuando eso ocurre.
 *
 * Falla al pedir la CONEXION y no al ejecutar el comando: es como se manifiesta
 * un Redis apagado o inalcanzable, que es el caso que importa. El mensaje imita
 * al del cliente real para que la prueba del log se parezca a la produccion.
 */
final class UnavailableRedis implements Factory
{
    /**
     * @param  \UnitEnum|string|null  $name
     * @return never
     */
    public function connection($name = null)
    {
        throw new RuntimeException('Connection refused [tcp://redis:6379]');
    }
}
