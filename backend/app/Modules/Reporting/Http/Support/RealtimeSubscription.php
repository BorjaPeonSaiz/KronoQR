<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use DateTimeZone;
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
 * cuatro casos: la instalacion lo ha apagado, no hay clave de Reverb configurada
 * —no hay a que conectarse—, el difusor no es de tiempo real (`null`, `log`, que
 * es lo que pasa en la suite de pruebas) o **la licencia no lo concede**
 * (tarea 5.3, ADR-023).
 *
 * ## Y `key` y `channels` no se sirven cuando esta apagado
 *
 * Sin clave de aplicacion no se abre el socket, y sin canales no hay nada que
 * pedir: es incoherente entregarle a un cliente lo que necesita para conectarse
 * justo despues de decirle que no hay tiempo real.
 *
 * **La negativa de verdad no esta aqui**, y conviene no confundirlo: la da
 * `routes/channels.php`, que no firma la suscripcion cuando la licencia no
 * concede el tiempo real. Esto es coherencia de la respuesta. Un cliente que
 * ignorase `enabled` y se inventara la clave se quedaria igualmente sin canal.
 */
final readonly class RealtimeSubscription
{
    /** La instalacion lo ha apagado (`REALTIME_ENABLED`). Lo arregla quien administra. */
    private const string BLOCKED_BY_INSTALLATION = 'installation';

    /** Falta configuracion de Reverb. Lo arregla quien despliega, no una renovacion. */
    private const string BLOCKED_BY_DEPLOYMENT = 'deployment';

    /** La licencia no lo concede. Es el UNICO motivo que se publica al cliente. */
    private const string BLOCKED_BY_LICENSE = 'license';

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
        /**
         * Por que no hay tiempo real, cuando la causa es la licencia (ADR-023,
         * tarea 5.3). `null` cuando lo hay, y tambien cuando lo que falta es la
         * configuracion de Reverb: eso ultimo lo arregla quien despliega, no
         * quien renueva, y el panel ya sabia decirlo.
         */
        public ?string $unavailableReason,
        /** Desde cuando, si la causa es una vigencia con fecha. */
        public ?string $unavailableSince,
    ) {}

    public static function forScope(AccessScope $scope, FeatureAvailability $license): self
    {
        $key = self::text('broadcasting.connections.reverb.key');

        // QUE condicion falla, no solo SI falla: es lo que permite no anunciar
        // «licencia caducada» cuando lo que pasa es que no hay Reverb.
        $blocker = self::blocker($key, $license);
        $enabled = $blocker === null;
        $byLicense = $blocker === self::BLOCKED_BY_LICENSE;

        return new self(
            enabled: $enabled,
            // Nula y no vacia cuando no hay Reverb configurado: «no hay clave» y
            // «la clave es la cadena vacia» no son lo mismo, y solo una existe.
            //
            // Y NULA TAMBIEN CUANDO NO HAY TIEMPO REAL, sea cual sea el motivo.
            // Sin clave de aplicacion no se abre el socket, y sin canales no hay
            // nada que pedir: es incoherente entregarle a un cliente lo que
            // necesita para conectarse justo despues de decirle que no hay
            // tiempo real.
            //
            // **La negativa de verdad no esta aqui**: la da `routes/channels.php`,
            // que no firma la suscripcion (tarea 5.3). Esto es coherencia de la
            // respuesta, no el control. `path` y `auth_endpoint` si se sirven:
            // son dos rutas estaticas del propio origen del panel, no dan acceso
            // a nada y quitarlas obligaria a hacer nulable en el contrato un
            // campo que nunca lo fue.
            key: $enabled && $key !== '' ? $key : null,
            path: Config::string('realtime.path'),
            authEndpoint: Config::string('realtime.auth_endpoint'),
            event: Config::string('realtime.event'),
            // Los canales que **esta** cuenta puede pedir, resueltos por su
            // alcance (RF-ID-03). No sustituyen a la autorizacion: quien firma
            // cada suscripcion es `routes/channels.php`.
            // Lista VACIA cuando no hay tiempo real, por lo mismo que `key` va
            // nula: sin canales no hay nada que pedir.
            channels: $enabled ? PresenceChannels::forScope($scope) : [],
            pollIntervalSeconds: Config::integer('realtime.poll_interval_seconds'),
            // El motivo solo viaja si la causa es LA LICENCIA. Si el tiempo real
            // esta apagado por configuracion o no hay Reverb, anunciar «licencia»
            // mandaria a quien lo lee a hablar con el comercial en lugar de con
            // quien administra el servidor.
            unavailableReason: $byLicense ? $license->restriction?->value : null,
            unavailableSince: $byLicense
                ? $license->since?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
                : null,
        );
    }

    /**
     * El **unico** sitio donde se decide si el tiempo real esta disponible.
     *
     * ADR-023 lo exige por escrito: la frontera entre registro legal y
     * funcionalidad accesoria se declara en un sitio y no como un
     * `if (license.expired)` repartido por el codigo. La tarea 2.4 dejo aqui el
     * hueco y la **tarea 5.3 lo ha rellenado**: la licencia entra por este
     * metodo y por ningun otro, y no ha cambiado nada mas — el endpoint sigue
     * respondiendo `enabled: false` y el panel ya sabia sondear.
     *
     * **Degrada a sondeo, no se apaga** (ADR-011, y la unica degradacion parcial
     * de ADR-023). La vista sigue enseñando quien esta dentro, con menos
     * frescura y diciendolo en pantalla. Apagarla del todo se percibiria como
     * una averia y produciria una llamada de soporte en lugar de una renovacion.
     *
     * Cuatro condiciones:
     *
     *   1. La instalacion no lo ha apagado (`REALTIME_ENABLED`).
     *   2. Hay una clave de aplicacion configurada: sin ella no hay a que
     *      conectarse, y anunciar que si lo hay dejaria al panel reintentando
     *      contra un puerto cerrado en vez de sondeando.
     *   3. El difusor es de tiempo real. Con `null` o `log` —la suite de
     *      pruebas, y una instalacion a medio configurar— nada llegaria al
     *      navegador.
     *   4. **La licencia lo concede** (`Feature::RealtimePresence`). El
     *      resultado ya viene resuelto del `FeatureGate`: esta clase no consulta
     *      la licencia, la recibe. `Reporting` no puede importar `Product`
     *      (doc 02 §1.6) y no lo hace: el puerto vive en `Shared` (ADR-025).
     *
     * ## Devuelve QUE condicion falla, no solo SI falla
     *
     * Porque el motivo que se publica tiene que ser el de verdad. Con un
     * booleano, una instalacion **sin Reverb configurado y con la licencia
     * caducada** anunciaba `license_expired`: quien lo leyera llamaria al
     * comercial cuando lo que hay que arreglar es el despliegue. El orden de las
     * comprobaciones es el de la accion siguiente, y la licencia se mira la
     * ultima por lo mismo.
     */
    private static function blocker(string $key, FeatureAvailability $license): ?string
    {
        if (! Config::boolean('realtime.enabled')) {
            return self::BLOCKED_BY_INSTALLATION;
        }

        if ($key === '') {
            return self::BLOCKED_BY_DEPLOYMENT;
        }

        if (! in_array(self::text('broadcasting.default'), ['reverb', 'pusher', 'ably'], true)) {
            return self::BLOCKED_BY_DEPLOYMENT;
        }

        return $license->enabled ? null : self::BLOCKED_BY_LICENSE;
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
     * **Lista blanca y no lista negra** en {@see self::blocker()} por lo
     * mismo: un difusor que no sepamos que entrega mensajes a un navegador se
     * trata como «no hay», no como «seguro que si».
     */
    private static function text(string $key): string
    {
        $value = Config::get($key);

        return \is_string($value) ? $value : '';
    }
}
