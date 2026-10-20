<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Cache-Control: no-store, private` en todas las respuestas de un grupo de
 * rutas (alias `no-store`; 2.3.0, bloque 8).
 *
 * Lo llevan las rutas cuyo cuerpo es una credencial: `/api/v1/auth/*` —el token
 * de sesion del acceso y del segundo factor, el secreto TOTP del alta, los
 * codigos del alta confirmada— y `POST /api/v1/setup/administrator`. Con el
 * `no-cache, private` que Laravel pone por omision, un navegador puede guardar
 * esa respuesta en su cache de disco, y el panel se abre a veces en el
 * ordenador compartido de recepcion.
 *
 * **Un middleware de grupo y no una cabecera por controlador**: una ruta nueva
 * dentro del grupo la hereda sin que nadie tenga que acordarse. Y no sustituye
 * a la que ya fijan los controladores de descargas, que la llevan por su
 * cuenta.
 *
 * `Cache-Control` y no `Pragma`: no hay ningun cliente HTTP/1.0 al que servir.
 * Se fija tambien en las respuestas de error del grupo, que no llevan secretos
 * pero tampoco tienen nada que ganar con quedarse en una cache.
 */
final class PreventResponseCaching
{
    public const string VALUE = 'no-store, private';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', self::VALUE);

        return $response;
    }
}
