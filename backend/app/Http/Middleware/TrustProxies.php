<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Que proxies puede creerse la aplicacion — y de serie, **ninguno** (RS-02,
 * RS-09, RS-12, regla dura 13).
 *
 * ## Por que existe, en lugar del de Laravel
 *
 * `Illuminate\Http\Middleware\TrustProxies` trae una heuristica pensada para las
 * plataformas de Laravel: si no se declaran proxies **y el `Host` de la peticion
 * termina en `.on-forge.com` o `.on-vapor.com`**, confia en `*` —es decir, en
 * cualquier origen— y pasa a leer la IP del cliente de `X-Forwarded-For`.
 *
 * En un despliegue de KronoQR eso es una puerta abierta, y no en teoria:
 *
 *   · `bootstrap/app.php` no declaraba proxies, asi que la rama de la heuristica
 *     estaba viva.
 *   · El Nginx del producto es `server_name _` —captura cualquier `Host`—, de
 *     modo que basta enviar `Host: lo-que-sea.on-forge.com` para entrar en ella.
 *   · A partir de ahi, `$request->ip()` es lo que diga la cabecera
 *     `X-Forwarded-For`, que la escribe el cliente.
 *
 * Y `$request->ip()` decide tres cosas del producto: si `/metrics` se sirve
 * ({@see RestrictToMetricsNetwork}, RS-09), que IP consta en `audit_log` para
 * cada acto con valor legal (regla dura 6), y contra que clave cuentan los
 * limites por IP de los intentos de autenticacion (RS-12). Las tres se
 * falsificaban con dos cabeceras.
 *
 * ## La regla: sin `TRUSTED_PROXIES`, no hay proxy de confianza
 *
 * Si `observability.trusted_proxies` esta vacia —el valor de serie— **no se
 * declara ningun proxy** y `$request->ip()` es `REMOTE_ADDR`, pase lo que pase
 * con el `Host`. Si tiene valor, se confia **solo** en esas direcciones o rangos
 * CIDR. No hay tercera rama y, en particular, no hay ninguna que dependa del
 * nombre de dominio: un nombre lo elige quien hace la peticion.
 *
 * ## Y de serie esta vacia porque no hace falta nada mas
 *
 * En el despliegue del producto Nginx habla con PHP por FastCGI, no por HTTP:
 * `fastcgi_params` pasa `REMOTE_ADDR = $remote_addr`, que **ya es la direccion
 * del cliente de verdad**. No hay ningun salto intermedio del que recuperar la
 * IP original, asi que confiar en `X-Forwarded-For` no anadiria informacion:
 * solo anadiria una forma de mentir.
 *
 * `TRUSTED_PROXIES` existe para la instalacion que ponga **otro** balanceador
 * por delante de Nginx (un HAProxy del cliente, un balanceador de su
 * organizacion). Es topologia de red del cliente, o sea configuracion y no
 * codigo (regla dura 13), y por lo mismo **no viaja en el paquete de
 * diagnostico**: describe la red interna de la organizacion.
 *
 * ## Que cabeceras se creen cuando si hay proxy declarado
 *
 * `X-Forwarded-For`, `-Host`, `-Port`, `-Proto` y `-Prefix`. **No**
 * `X-Forwarded-AWS-ELB` ni `Forwarded`: ninguna instalacion del producto va
 * detras de un ELB, y cuantas menos cabeceras se interpreten, menos superficie
 * hay.
 */
final class TrustProxies
{
    /**
     * Las cabeceras del estandar de facto, sin las de AWS.
     *
     * @see \Symfony\Component\HttpFoundation\Request
     */
    private const int HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Request::setTrustedProxies(self::proxies(), self::HEADERS);

        return $next($request);
    }

    /**
     * Las direcciones o rangos CIDR de `TRUSTED_PROXIES`, separados por coma o
     * por espacio.
     *
     * Lista vacia = ningun proxy de confianza = `$request->ip()` es
     * `REMOTE_ADDR`. Es el valor de serie y el correcto para el despliegue del
     * producto.
     *
     * @return list<string>
     */
    public static function proxies(): array
    {
        $configured = config('observability.trusted_proxies');

        if (! \is_string($configured) || trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/', trim($configured)) ?: [],
            static fn (string $proxy): bool => $proxy !== '',
        ));
    }
}
