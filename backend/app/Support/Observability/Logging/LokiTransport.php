<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

/**
 * El unico viaje a la red del canal de log (decision 8 de la ficha 3.1).
 *
 * Existe para que {@see LokiHandler} —donde vive lo que hay que probar: el
 * formato del envio, la agrupacion y que un fallo no se propague— pueda probarse
 * sin levantar un Loki ni fingir un cliente HTTP entero.
 *
 * **Quien lo implemente no puede lanzar.** Un fallo de red al enviar una linea de
 * log no puede convertirse en un error de la peticion que la escribio: se
 * devuelve `false` y se sigue.
 */
interface LokiTransport
{
    /**
     * @param  string  $url  URL completa de `POST /loki/api/v1/push`.
     * @param  string  $payload  Cuerpo JSON ya compuesto.
     * @param  float  $timeoutSeconds  Techo total del envio.
     * @return bool Si Loki lo acepto. `false` no es un error del que haya que enterarse: es una linea de log perdida.
     */
    public function push(string $url, string $payload, float $timeoutSeconds): bool;
}
