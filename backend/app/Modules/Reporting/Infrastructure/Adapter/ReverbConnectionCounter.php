<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Port\RealtimeConnectionCounter;
use Illuminate\Support\Facades\Config;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use Throwable;

/**
 * Pregunta a Reverb cuantas conexiones tiene vivas (`websocket_connections_active`,
 * doc 02 §8.2).
 *
 * ## Por que por HTTP y no de otra forma
 *
 * Reverb corre en **otro proceso** —su propio contenedor del `docker compose`—
 * y no expone metricas de Prometheus. Lo unico que publica es su API HTTP
 * compatible con Pusher, y ahi `GET /apps/{id}/connections` devuelve exactamente
 * el numero que hace falta. Se firma con el secreto de aplicacion, que no sale
 * del servidor.
 *
 * **El cliente de Pusher ya esta instalado**: lo arrastra `laravel/reverb` como
 * dependencia obligatoria y es el mismo que usa el difusor. Firmar la peticion a
 * mano habria significado reimplementar el esquema de firma —metodo, ruta,
 * parametros ordenados, HMAC— para ahorrar una dependencia que ya esta puesta.
 *
 * ## Nunca lanza
 *
 * Quien llama es una tarea programada de metricas, y una metrica que se cae no
 * puede llevarse por delante a las demas. Cualquier fallo —Reverb parado, firma
 * invalida, configuracion a medias— se traduce en `null` y una linea de log.
 *
 * **`null` no es `0`.** Cero significa «nadie tiene el panel abierto», que de
 * madrugada es lo normal; nulo significa «no se ha podido preguntar», que es
 * justo la averia que esta metrica existe para distinguir de una caida del
 * sistema (ADR-011). Quien publica la metrica omite la serie en ese caso.
 *
 * ## Sin configuracion, sin pregunta
 *
 * En una instalacion sin `REVERB_APP_ID` —o en la suite de pruebas, donde el
 * difusor es `null`— no hay nada a lo que preguntar y devolver `null` es la
 * respuesta honesta. No se intenta la llamada para no gastar el tiempo de espera
 * de una conexion que no existe.
 */
final readonly class ReverbConnectionCounter implements RealtimeConnectionCounter
{
    public function __construct(private LoggerInterface $logger) {}

    public function activeConnections(): ?int
    {
        $key = $this->text('key');
        $secret = $this->text('secret');
        $appId = $this->text('app_id');

        if ($key === '' || $secret === '' || $appId === '') {
            return null;
        }

        $scheme = $this->text('options.scheme') ?: 'http';

        try {
            $pusher = new Pusher($key, $secret, $appId, [
                'host' => $this->text('options.host') ?: '127.0.0.1',
                'port' => $this->port(),
                'scheme' => $scheme,
                'useTLS' => $scheme === 'https',
                // Tres segundos y no los treinta de serie: esto lo llama una
                // tarea que corre cada minuto, y esperar medio minuto a un
                // servicio caido solo retrasa el resto de las metricas.
                'timeout' => 3,
            ]);

            /** @var array<string, mixed> $response */
            $response = $pusher->get('/connections', [], true);

            $connections = $response['connections'] ?? null;

            return is_numeric($connections) ? (int) $connections : null;
        } catch (Throwable $failure) {
            $this->logger->warning('No se ha podido consultar las conexiones vivas de Reverb.', [
                'exception' => $failure::class,
                'reason' => $failure->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Lectura **tolerante** de una clave de texto de la conexion.
     *
     * `Config::string()` es estricto, y aqui no puede serlo: `env()` convierte
     * en `null` tanto una variable sin declarar como la cadena `null`, asi que
     * una instalacion sin Reverb configurado deja estas claves nulas. Eso no es
     * una averia —significa «no hay a quien preguntar»— y esta clase ya sabe
     * responder a eso con un `null`.
     */
    private function text(string $key): string
    {
        $value = Config::get('broadcasting.connections.reverb.'.$key);

        return is_string($value) ? $value : '';
    }

    private function port(): int
    {
        $value = Config::get('broadcasting.connections.reverb.options.port');

        return is_numeric($value) ? (int) $value : 8080;
    }
}
