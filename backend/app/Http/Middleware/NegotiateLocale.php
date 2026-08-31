<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
 * - El idioma se acota a `app.supported_locales` (`APP_SUPPORTED_LOCALES`): los
 *   idiomas son configuracion de la instalacion (regla dura 13, RF-PD-01), no
 *   una lista en el codigo. La tarea 5.8 los llevara a `installation_settings`;
 *   hasta entonces viven en `.env`.
 * - Sin cabecera, o sin ningun idioma en comun, se responde en `app.locale`,
 *   que es exactamente lo que pasaba antes: nadie pierde nada.
 * - Los DOCUMENTOS no se negocian. Un CSV para la Inspeccion o el PDF de un
 *   informe salen en el idioma de la instalacion aunque el navegador pida otro,
 *   porque el idioma que importa es el del programa que abrira el fichero (ver
 *   `CsvDialect::delimiterFor()`). Las rutas que generan documentos llevan
 *   {@see UseInstallationLocale}, que restaura el idioma capturado aqui.
 *
 * El idioma de la instalacion se captura ANTES de tocar nada porque
 * `Application::setLocale()` tambien escribe `config('app.locale')`: una vez
 * negociado ya no se distingue lo configurado de lo pedido.
 *
 * Vive en el grupo `api`, detras de la observabilidad: no puede tumbar una
 * peticion (una cabecera rara solo acaba en el idioma de la instalacion).
 */
final readonly class NegotiateLocale
{
    /** Atributo de la peticion con el idioma configurado en la instalacion. */
    public const string INSTALLATION_LOCALE = 'kronoqr.installation_locale';

    public function __construct(
        private Application $app,
        private Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $installation = $this->app->getLocale();
        $request->attributes->set(self::INSTALLATION_LOCALE, $installation);

        // El de la instalacion va primero: es lo que Symfony devuelve cuando no
        // hay cabecera o ninguno de los idiomas pedidos esta en la lista. Las
        // etiquetas con region (`es-ES`) las reduce el al idioma base.
        $negotiated = $request->getPreferredLanguage([$installation, ...$this->supportedLocales()]);

        $this->app->setLocale($negotiated ?? $installation);

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
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
