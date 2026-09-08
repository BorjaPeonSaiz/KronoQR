<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Telemetry;

use App\Modules\Product\Application\Port\TelemetrySender;
use App\Modules\Product\Domain\ValueObject\TelemetryDelivery;
use App\Modules\Product\Domain\ValueObject\TelemetryReport;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * **El unico cliente HTTP saliente de `app/`** (**RF-PD-12**, ADR-020, ficha
 * 5.10 punto 8).
 *
 * `tests/Architecture/OutboundChannelsTest.php` comprueba que ningun otro
 * fichero de `app/` nombra el cliente HTTP de Laravel, Guzzle, `curl_*` ni
 * `file_get_contents('http`. Esa prueba es la verificacion literal de ADR-020:
 * *ningun canal del producto envia datos al fabricante fuera del paquete de
 * diagnostico y de la telemetria*.
 *
 * ## Los tiempos de espera son cortos a proposito
 *
 * Tres segundos para conectar y diez en total. Este envio corre en una tarea del
 * planificador de madrugada, no en una peticion, pero un `POST` sin tiempo de
 * espera contra un destino que acepta la conexion y no contesta deja un proceso
 * PHP colgado hasta que alguien lo mata — y el proceso del planificador es el
 * mismo que ejecuta la deteccion de incidencias y la purga.
 *
 * ## Sin redirecciones
 *
 * `allow_redirects => false`. Un `302` es la forma mas barata de que un destino
 * comprometido —o un portal cautivo de la wifi del hotel— reenvie el documento a
 * otro sitio, y de que un `https` acabe en `http`. Si el destino cambia, lo
 * cambia el cliente en su `.env`; nadie lo cambia por el a mitad de una
 * peticion.
 *
 * ## TLS verificado, y no hay bandera para desactivarlo
 *
 * A diferencia de la sonda de certificado de `doctor`, que admite autofirmados
 * porque su trabajo es mirar el certificado del propio cliente. Aqui sale un
 * documento hacia fuera: sin verificacion, cualquiera en la red del hotel lo
 * leeria y lo alteraria. `security.tls_allow_self_signed` **no se consulta**.
 *
 * ## Un reintento, a los cinco segundos, y nada mas hasta la semana siguiente
 *
 * Cubre el fallo que de verdad se da: la ventana en la que el enlace del hotel
 * se esta renegociando. Un reintento con retroceso exponencial durante minutos
 * convertiria una tarea de fondo en algo que ocupa un proceso, y el dato de esta
 * semana no vale lo suficiente como para insistir. La espera se inyecta para que
 * las pruebas la pongan a cero: una suite que duerme cinco segundos por caso es
 * una suite que nadie ejecuta.
 *
 * ## No lanza. Devuelve un resultado.
 *
 * Ver {@see TelemetryDelivery}. El escenario normal de este producto es una
 * instalacion sin salida a internet.
 */
final readonly class HttpTelemetrySender implements TelemetrySender
{
    /** Segundos para establecer la conexion. */
    private const int CONNECT_TIMEOUT = 3;

    /** Segundos de la peticion completa, conexion incluida. */
    private const int TOTAL_TIMEOUT = 10;

    public function __construct(
        private HttpClient $http,
        private string $productVersion,
        private int $retryDelaySeconds = 5,
    ) {}

    public function send(TelemetryReport $report, string $endpoint): TelemetryDelivery
    {
        $body = $report->toJson();

        $first = $this->attempt($body, $endpoint, 1);

        if ($first->delivered) {
            return $first;
        }

        if ($this->retryDelaySeconds > 0) {
            sleep($this->retryDelaySeconds);
        }

        // El unico reintento. Escrito sin bucle a proposito: un `for` invita a
        // subir el tope, y el numero de intentos es una decision documentada
        // (ficha 5.10 punto 8), no un parametro.
        return $this->attempt($body, $endpoint, 2);
    }

    private function attempt(string $body, string $endpoint, int $attempt): TelemetryDelivery
    {
        try {
            $response = $this->http
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TOTAL_TIMEOUT)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    // Solo la version del producto. Ni el nombre del hotel, ni la
                    // URL de la instalacion, ni el `installation_id`: eso ya va
                    // en el cuerpo, donde el cliente lo ve documentado.
                    'User-Agent' => 'KronoQR/'.$this->productVersion,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint);
        } catch (Throwable $failure) {
            // La CLASE, jamas el mensaje: lleva el host y a veces la URL entera.
            return TelemetryDelivery::failed($failure::class, $attempt);
        }

        $status = $response->status();

        return $response->successful()
            ? TelemetryDelivery::delivered($status, $attempt)
            : TelemetryDelivery::failed('http_'.$status, $attempt, $status);
    }
}
