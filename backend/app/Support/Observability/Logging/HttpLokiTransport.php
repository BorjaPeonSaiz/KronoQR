<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * El envio a Loki por HTTP (decision 8 de la ficha 3.1).
 *
 * ## Esto NO es un canal hacia el fabricante
 *
 * Loki vive **en el servidor del cliente** (doc 02 §1.4: «Observabilidad (en el
 * mismo servidor)»), igual que PostgreSQL, Redis o el servidor de correo. ADR-020
 * y la regla dura 16 prohiben que el producto envie datos del cliente **al
 * fabricante**, y `LOKI_URL` es una direccion de su propia red — de serie,
 * vacia; con el perfil `observability`, `http://loki:3100`, que ni siquiera sale del
 * `docker compose`.
 *
 * `OutboundChannelsTest` conoce este fichero por su nombre y por ese motivo, con
 * la misma mecanica con la que exceptua la raiz de composicion de `Product`: la
 * excepcion esta acotada a **un fichero**, y un `Http::post()` en cualquier otro
 * sitio del armazon sigue rompiendo la prueba.
 *
 * ## No lanza, no reintenta, no espera
 *
 * Un segundo de techo total —conexion incluida— y un intento. Ver
 * {@see LokiHandler}: el log tecnico no puede convertirse en una dependencia del
 * camino de fichaje.
 */
final readonly class HttpLokiTransport implements LokiTransport
{
    public function __construct(private HttpClient $http) {}

    public function push(string $url, string $payload, float $timeoutSeconds): bool
    {
        try {
            $timeout = max($timeoutSeconds, 0.1);

            return $this->http
                ->timeout($timeout)
                // Un colector que acepta la conexion y no contesta es el caso que
                // mas duele: sin techo de conexion, el techo total no acota nada
                // en un DNS que no resuelve.
                ->connectTimeout(min($timeout, 0.5))
                ->withBody($payload, 'application/json')
                ->post($url)
                ->successful();
        } catch (Throwable) {
            // Ver el docblock: una linea de log perdida no es un error del que
            // haya que enterarse por una excepcion.
            return false;
        }
    }
}
