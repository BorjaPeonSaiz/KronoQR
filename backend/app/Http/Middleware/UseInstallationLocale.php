<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deshace la negociacion de idioma en las rutas que generan un documento.
 *
 * Un fichero se entrega a un tercero —la Inspeccion, una gestoria, la propia
 * persona que lo archiva— y lo abrira un programa cuyo idioma no es el del
 * navegador que lo descargo. Por eso el idioma de los documentos es
 * configuracion de la instalacion (`APP_LOCALE`, regla dura 13) y no lo elige
 * la cabecera `Accept-Language`, que {@see NegotiateLocale} si aplica al resto
 * de la API. Este middleware restaura el idioma que aquel capturo antes de
 * negociar, para las rutas que lo declaran con el alias `locale.installation`:
 * la exportacion del informe por periodo, la exportacion legal, la descarga del
 * registro personal en el portal y la impresion de tarjetas.
 *
 * Consecuencia asumida: un `422` de esas rutas tambien sale en el idioma de la
 * instalacion. El idioma se fija ANTES de validar porque es durante la
 * generacion cuando hace falta, y no hay forma de saber de antemano si la
 * peticion acabara en fichero o en error.
 *
 * Es un middleware de ruta y no una comprobacion dentro de cada controlador
 * para que la decision se lea en `routes/api_v1.php`, junto al resto de lo que
 * una ruta exige, y para que anadirla a un documento nuevo sea una linea.
 */
final readonly class UseInstallationLocale
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $installation = $request->attributes->get(NegotiateLocale::INSTALLATION_LOCALE);

        if (\is_string($installation) && $installation !== '') {
            $this->app->setLocale($installation);
        }

        return $next($request);
    }
}
