<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\Config;

/**
 * Lo que el panel necesita para abrir el WebSocket: el esquema
 * `RealtimeSubscription` del contrato (**ADR-011**).
 *
 * ## Viaja en la respuesta y no en la build de la SPA
 *
 * Es la consecuencia directa de ADR-017 y de la regla dura 13. El panel se
 * compila **una vez** y se instala en el servidor de cada cliente: una clave de
 * aplicacion o un puerto metidos en el paquete obligarian a recompilar la SPA
 * por instalacion, que es exactamente lo que ese ADR prohibe. Aqui los datos de
 * conexion son configuracion del servidor y llegan al cliente con la primera
 * respuesta que ya iba a pedir.
 *
 * ## Sin host ni puerto, a proposito
 *
 * El WebSocket se atraviesa por el **mismo origen** desde el que se sirvio el
 * panel: Nginx proxifica `path` hacia el contenedor de Reverb. El cliente compone
 * `wss://<su propio origen><path>/<key>` y no hay ni CORS, ni un segundo nombre
 * de dominio que configurar en cada hotel, ni un certificado mas que renovar.
 *
 * ## `key` no es un secreto y `secret` no sale de aqui
 *
 * `key` es la clave publica de aplicacion del protocolo Pusher, la que identifica
 * la aplicacion en el saludo del WebSocket; va en el javascript de cualquier
 * instalacion que use este stack. Lo que autoriza de verdad es la firma que
 * devuelve `auth_endpoint` para cada canal privado, y esa la calcula el servidor
 * con `REVERB_APP_SECRET`, que **no aparece en ninguna respuesta**.
 *
 * ## Apagado significa sondear, no romperse
 *
 * `enabled = false` es la degradacion honesta del ADR-011 y la unica degradacion
 * **parcial** de ADR-023: la vista sigue funcionando por sondeo cada
 * `poll_interval_seconds` y el panel lo anuncia en pantalla (RNF-D-03). Ocurre en
 * tres casos: la instalacion lo ha apagado, no hay clave de Reverb configurada
 * —no hay a que conectarse— o el difusor no es de tiempo real (`null`, `log`),
 * que es lo que pasa en la suite de pruebas.
 */
final readonly class RealtimeSubscription
{
    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        public bool $enabled,
        public ?string $key,
        public string $path,
        public string $authEndpoint,
        public string $event,
        public array $channels,
        public int $pollIntervalSeconds,
    ) {}

    public static function forScope(AccessScope $scope): self
    {
        $key = self::text('broadcasting.connections.reverb.key');

        return new self(
            enabled: self::isAvailable($key),
            // Nula y no vacia cuando no hay Reverb configurado: «no hay clave» y
            // «la clave es la cadena vacia» no son lo mismo, y solo una existe.
            key: $key === '' ? null : $key,
            path: Config::string('realtime.path'),
            authEndpoint: Config::string('realtime.auth_endpoint'),
            event: Config::string('realtime.event'),
            // Los canales que **esta** cuenta puede pedir, resueltos por su
            // alcance (RF-ID-03). No sustituyen a la autorizacion: quien firma
            // cada suscripcion es `routes/channels.php`.
            channels: PresenceChannels::forScope($scope),
            pollIntervalSeconds: Config::integer('realtime.poll_interval_seconds'),
        );
    }

    /**
     * El **unico** sitio donde se decide si el tiempo real esta disponible.
     *
     * ADR-023 lo exige por escrito: la frontera entre registro legal y
     * funcionalidad accesoria se declara en un sitio y no como un
     * `if (license.expired)` repartido por el codigo. Cuando el modulo `Product`
     * exponga el puerto de licencia —tarea 5.3—, la comprobacion entra **aqui**
     * y en ningun otro lugar, y no cambia nada mas: el endpoint de sondeo sigue
     * respondiendo con `enabled: false` y el panel ya sabe que hacer con eso.
     *
     * Hoy son tres condiciones y ninguna es la licencia:
     *
     *   1. La instalacion no lo ha apagado (`REALTIME_ENABLED`).
     *   2. Hay una clave de aplicacion configurada: sin ella no hay a que
     *      conectarse, y anunciar que si lo hay dejaria al panel reintentando
     *      contra un puerto cerrado en vez de sondeando.
     *   3. El difusor es de tiempo real. Con `null` o `log` —la suite de
     *      pruebas, y una instalacion a medio configurar— nada llegaria al
     *      navegador.
     */
    private static function isAvailable(string $key): bool
    {
        if (! Config::boolean('realtime.enabled')) {
            return false;
        }

        if ($key === '') {
            return false;
        }

        return \in_array(self::text('broadcasting.default'), ['reverb', 'pusher', 'ably'], true);
    }

    /**
     * Lectura **tolerante** de una clave de configuracion de texto.
     *
     * `Config::string()` es estricto y aqui no puede serlo, por una razon
     * concreta y no por comodidad: `env()` convierte la cadena `null` de un
     * fichero de entorno en un `null` de PHP, asi que una instalacion con
     * `BROADCAST_CONNECTION=null` —la forma canonica de apagar la difusion, y la
     * que usa la suite de pruebas— deja `broadcasting.default` **nulo**. Lo
     * mismo pasa con `REVERB_APP_KEY` sin declarar.
     *
     * Ninguno de los dos casos es una averia: los dos significan «no hay tiempo
     * real», que es justo lo que esta clase tiene que saber responder. Reventar
     * ahi convertiria una instalacion sin WebSocket en un `500` en la pantalla
     * de presencia, cuando lo correcto es un `enabled: false` y sondeo.
     *
     * **Lista blanca y no lista negra** en {@see self::isAvailable()} por lo
     * mismo: un difusor que no sepamos que entrega mensajes a un navegador se
     * trata como «no hay», no como «seguro que si».
     */
    private static function text(string $key): string
    {
        $value = Config::get($key);

        return \is_string($value) ? $value : '';
    }
}
