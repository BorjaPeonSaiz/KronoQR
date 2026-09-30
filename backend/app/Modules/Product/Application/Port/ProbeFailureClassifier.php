<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use Throwable;

/**
 * **¿Que servicio estaba caido cuando reviento una sonda?** (PR2, RF-PD-13).
 *
 * `product:doctor` atrapa la excepcion de una sonda que revienta y la convierte
 * en una comprobacion `failure`. Hasta la 2.2.0 esa comprobacion decia siempre
 * «es un fallo del producto, envia el paquete a soporte», y con Redis parado lo
 * decian dos familias a la vez —configuracion y licencia leen a traves de la
 * cache—: el informe mandaba a quien lo leia a abrir una incidencia al
 * fabricante por un contenedor parado que el mismo informe ya señalaba.
 *
 * Saber si una excepcion es «Redis no contesta» o «PostgreSQL no contesta» es
 * conocer las clases de dos clientes de infraestructura, y eso no entra en
 * `Application/`. Por eso es un puerto.
 */
interface ProbeFailureClassifier
{
    public const string REDIS = 'redis';

    public const string DATABASE = 'database';

    /**
     * El servicio del que depende la sonda y que no ha respondido, o `null` si la
     * excepcion no es de conexion y el fallo es, de verdad, del producto.
     *
     * @return self::REDIS|self::DATABASE|null
     */
    public function unavailableService(Throwable $failure): ?string;
}
