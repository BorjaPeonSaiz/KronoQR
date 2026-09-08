<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locale\NegotiableLocales;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Elige el idioma de la respuesta a partir de `Accept-Language`.
 *
 * ## Por que existe
 *
 * Los textos que la API escribe para una persona —los mensajes de validacion,
 * los criterios de un informe (`meta.criteria`), el campo desconocido de
 * `RejectsUnknownInput`— salian siempre en `APP_LOCALE`, fuera cual fuera el
 * idioma del panel o del portal: no habia nada que negociara idioma por
 * peticion. El recuadro «Hay datos que revisar» de un panel en castellano
 * enseñaba «The include open shifts field must be true or false».
 *
 * ## Que decide y que no
 *
 * - El idioma se acota a **los que la instalacion ofrece**, que desde la tarea
 *   5.8 son las claves `LOCALE_DEFAULT` y `LOCALE_AVAILABLE` de
 *   `installation_settings`: el cliente las cambia desde el panel, el cambio
 *   queda auditado y rige en la peticion siguiente, sin reiniciar nada (regla
 *   dura 13, RF-PD-01). Es la misma lista que publica `GET /api/v1/branding` y
 *   con la que el quiosco filtra su selector, asi que no puede haber dos
 *   opiniones sobre que idiomas ofrece la instalacion.
 * - Sin cabecera, o sin ningun idioma en comun, se responde en el idioma por
 *   defecto de la instalacion.
 * - Los DOCUMENTOS no se negocian. Un CSV para la Inspeccion o el PDF de un
 *   informe salen en el idioma de la instalacion aunque el navegador pida otro,
 *   porque el idioma que importa es el del programa que abrira el fichero (ver
 *   `CsvDialect::delimiterFor()`). Las rutas que generan documentos llevan
 *   {@see UseInstallationLocale}, que restaura el idioma capturado aqui.
 *
 * ## `APP_LOCALE` y `APP_SUPPORTED_LOCALES` siguen ahi, como RESPALDO
 *
 * Ya no son la fuente. Se aplican si la lectura de la configuracion falla —base
 * de datos caida, Redis caido, una fila corrupta—, y ese respaldo no es un
 * adorno: **esto se ejecuta en todas las peticiones**, incluida la que devuelve
 * el error que explica que la base de datos no responde. Sin el, una instalacion
 * con PostgreSQL caido daria 500 en cada endpoint en vez de decir que le pasa.
 *
 * ## Las dos SONDAS no consultan nada
 *
 * `GET /api/v1/health` y `GET /api/v1/ready` se resuelven con el idioma de
 * arranque y sin preguntar a nadie. `/health` es una sonda de VIDA y su regla,
 * desde la tarea 1.7, es que no toca dependencias: una sonda que consultara
 * PostgreSQL para elegir el idioma de una respuesta que no lleva texto haria que
 * Docker reiniciara el contenedor de PHP cuando lo que esta caido es PostgreSQL.
 * Que el idioma se resuelva **perezosamente**, y no en el constructor, es lo que
 * hace cierta esta frase: un `NegotiableLocales` inyectado se construiria en cada
 * peticion, sondas incluidas.
 *
 * ## Por que el contrato vive en `App\Support` y no en `Shared`
 *
 * Porque esta clase esta fuera de los modulos y Deptrac no le deja nombrar
 * ningun tipo de `App\Modules\*` — frontera intacta desde la tarea 0.2. La
 * direccion admitida es la contraria: el adaptador de `Product` implementa
 * {@see NegotiableLocales} y el contenedor los une. Es la misma solucion, por la
 * misma razon, que usa `LicenseStateProbe` para el estado de licencia de
 * `/health`.
 *
 * El idioma de la instalacion se captura ANTES de tocar nada porque
 * `Application::setLocale()` tambien escribe `config('app.locale')`: una vez
 * negociado ya no se distingue lo configurado de lo pedido.
 *
 * Vive en el grupo `api`, detras de la observabilidad: no puede tumbar una
 * peticion (una cabecera rara, o una configuracion ilegible, solo acaban en el
 * idioma de la instalacion).
 */
final readonly class NegotiateLocale
{
    /** Atributo de la peticion con el idioma configurado en la instalacion. */
    public const string INSTALLATION_LOCALE = 'kronoqr.installation_locale';

    /**
     * Las dos sondas, por nombre de ruta.
     *
     * Por NOMBRE y no por URI: el prefijo `/api/v1` se declara en
     * `bootstrap/app.php` y una comparacion de cadenas se romperia en silencio si
     * algun dia cambiara. Los nombres los fija `routes/api_v1.php`.
     */
    private const array PROBE_ROUTES = ['health.live', 'health.ready'];

    public function __construct(
        private Application $app,
        private Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $installation = $this->app->getLocale();

        if ($this->isProbe($request)) {
            // Ni una consulta. La respuesta de una sonda no lleva texto que
            // traducir y su valor esta justamente en no depender de nada.
            $request->attributes->set(self::INSTALLATION_LOCALE, $installation);

            return $next($request);
        }

        $locales = $this->locales();

        $installation = $locales['default'];
        $request->attributes->set(self::INSTALLATION_LOCALE, $installation);
        $this->app->setLocale($installation);

        // El de la instalacion va primero: es lo que Symfony devuelve cuando no
        // hay cabecera o ninguno de los idiomas pedidos esta en la lista. Las
        // etiquetas con region (`es-ES`) las reduce el al idioma base.
        $negotiated = $request->getPreferredLanguage([$installation, ...$locales['available']]);

        $this->app->setLocale($negotiated ?? $installation);

        return $next($request);
    }

    private function isProbe(Request $request): bool
    {
        $route = $request->route();

        $name = $route instanceof RoutingRoute ? $route->getName() : null;

        return $name !== null && \in_array($name, self::PROBE_ROUTES, true);
    }

    /**
     * Los idiomas de la instalacion, con respaldo de configuracion.
     *
     * @return array{default: string, available: list<string>}
     */
    private function locales(): array
    {
        try {
            $locales = $this->app->make(NegotiableLocales::class);

            return [
                'default' => $locales->defaultLocale(),
                'available' => $locales->availableLocales(),
            ];
        } catch (Throwable) {
            // Ultimo recurso: ni siquiera se ha podido construir el adaptador
            // —un modulo sin registrar, un contenedor a medias—. El adaptador ya
            // tiene su propio respaldo para el caso normal de una base de datos
            // caida; esto cubre el anormal. Sin log: esto corre en cada peticion.
            return [
                'default' => $this->app->getLocale(),
                'available' => $this->configuredLocales(),
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function configuredLocales(): array
    {
        $configured = $this->config->get('app.supported_locales', []);

        if (! \is_array($configured)) {
            return [];
        }

        $locales = [];

        foreach ($configured as $locale) {
            if (\is_string($locale) && $locale !== '') {
                $locales[] = $locale;
            }
        }

        return $locales;
    }
}
