<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /metrics` solo desde la red interna (doc 02 §8.1, doc 01 Anexo B:
 * «`GET /metrics` — Metricas Prometheus — [red interna]», RS-09).
 *
 * ## Es la segunda guarda, no la primera
 *
 * Nginx ya rechaza con `403` lo que no cae en `METRICS_ALLOW_CIDR`, con un
 * bloque `geo` que existe desde la Fase 0
 * (`infra/docker/nginx/templates/kronoqr.conf.template`). Esto no lo sustituye:
 * lo duplica dentro de la aplicacion **para que una plantilla de Nginx mal
 * editada no deje las series a la vista**. Es la unica ruta del producto en la
 * que una errata de configuracion del borde publica datos de operacion de un
 * hotel —quioscos, ritmo de fichaje, errores— sin que nadie se entere.
 *
 * ## Es una cuestion de RED, no de identidad
 *
 * Ningun rol y ningun token abren `/metrics`: no hay `auth:sanctum` ni policy,
 * porque quien la lee es Prometheus, que no tiene cuenta. La «autorizacion
 * negativa» que exige el §9.5 para esta ruta es justo esta: desde fuera del
 * CIDR, `403`.
 *
 * ## La IP que se compara es `REMOTE_ADDR`, LEIDA DIRECTAMENTE
 *
 * Y no `$request->ip()`, que es lo que hacia la primera version. Detras de este
 * borde `REMOTE_ADDR` **es la direccion del cliente de verdad**: Nginx habla con
 * PHP por FastCGI y `fastcgi_params` pasa `$remote_addr`, no la IP del propio
 * Nginx. No hay ningun salto del que recuperar la IP original, asi que
 * `X-Forwarded-For` no anadiria informacion — solo una forma de mentir.
 *
 * Se lee del `$_SERVER` y no por `$request->ip()` **aunque
 * {@see TrustProxies} ya haya cerrado la heuristica del framework**. Es una
 * segunda guarda: su valor esta en no depender de que otra pieza este bien
 * configurada, y `$request->ip()` depende de la lista de proxies de confianza.
 * Con `TRUSTED_PROXIES` mal puesta —o con un `*` copiado de un tutorial— esta
 * puerta seguiria cerrada. Una guarda de red que se apoya en configuracion de
 * red no es una guarda.
 *
 * ## El `403` va vacio, y NO es indistinguible del de Nginx
 *
 * Sin cuerpo y sin `problem+json`. Esta ruta esta fuera de `/api/v1` y de su
 * contrato: quien la llama es un sondeador, y un cuerpo explicando el motivo le
 * diria a quien busca el endpoint que ha dado con el.
 *
 * Lo que **no** se puede afirmar —y la primera version de este docblock
 * afirmaba— es que la respuesta sea indistinguible de la del borde: el `403` de
 * Nginx lleva su pagina HTML de error y su cabecera `Server`, y este va vacio.
 * Se puede distinguir cual de las dos guardas rechazo. Se asume: lo que importa
 * es que ninguna de las dos revele el CIDR ni confirme que la ruta existe, y
 * eso si se cumple. Igualar los dos cuerpos exigiria que la aplicacion
 * reprodujera la pagina de error de Nginx, que es una copia mas que mantener
 * para tapar una diferencia que no dice nada util a quien sondea.
 */
final class RestrictToMetricsNetwork
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // `REMOTE_ADDR` en crudo, nunca `$request->ip()`: ver el docblock.
        $address = $request->server->get('REMOTE_ADDR');

        if (! \is_string($address) || ! self::allows($address)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Si la direccion cae en alguno de los rangos configurados.
     *
     * `null` —una peticion sin `REMOTE_ADDR`, que en la practica solo pasa en
     * pruebas de consola— **no pasa**: fallar cerrado es lo unico admisible en
     * una guarda de red.
     */
    public static function allows(?string $address): bool
    {
        if ($address === null || $address === '') {
            return false;
        }

        foreach (self::ranges() as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los rangos de `observability.metrics.allow_cidr`.
     *
     * Se admiten varios separados por coma o por espacio aunque Nginx solo tome
     * uno en su `geo`: una instalacion con dos redes de sondeo tendria que
     * añadir lineas en la plantilla del borde, y aqui basta con la lista. Lo que
     * NO se admite es la lista vacia — sin rangos no entra nadie.
     *
     * @return list<string>
     */
    private static function ranges(): array
    {
        $configured = config('observability.metrics.allow_cidr');

        if (! \is_string($configured)) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/', trim($configured)) ?: [],
            static fn (string $range): bool => $range !== '',
        ));
    }

    /**
     * Comparacion bit a bit sobre la representacion binaria, que es lo unico que
     * funciona igual en IPv4 y en IPv6.
     *
     * Sin prefijo, la entrada se trata como una direccion suelta: `/32` en IPv4
     * y `/128` en IPv6. Un prefijo fuera de rango, una direccion ilegible o una
     * mezcla de familias no casan — nunca abren.
     *
     * Publica para poder probarla sola: es la unica logica de esta clase con
     * casos de borde de verdad —el bit suelto de un `/23`, la mezcla de
     * familias, el prefijo que no es un numero— y probarla a traves de una
     * peticion HTTP obligaria a levantar el framework para comparar dos cadenas.
     */
    public static function inRange(string $address, string $range): bool
    {
        $binaryAddress = @inet_pton($address);

        if ($binaryAddress === false) {
            return false;
        }

        $network = self::networkOf($range, \strlen($binaryAddress));

        if ($network === null) {
            return false;
        }

        [$binarySubnet, $bits] = $network;

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($binaryAddress, $binarySubnet, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (\ord($binaryAddress[$wholeBytes]) & $mask) === (\ord($binarySubnet[$wholeBytes]) & $mask);
    }

    /**
     * Descompone `10.91.0.0/16` en su red binaria y su numero de bits, ya
     * validado contra la familia de la direccion que se compara.
     *
     * Devuelve `null` —«no casa»— ante cualquier cosa rara: prefijo que no es
     * un numero, prefijo fuera de rango, subred ilegible o mezcla de IPv4 con
     * IPv6. Una guarda de red no interpreta lo que no entiende.
     *
     * @param  int  $addressLength  Longitud en bytes de la direccion a comparar: 4 en
     *                              IPv4, 16 en IPv6.
     * @return array{0: string, 1: int}|null
     */
    private static function networkOf(string $range, int $addressLength): ?array
    {
        [$subnet, $prefix] = str_contains($range, '/')
            ? explode('/', $range, 2)
            : [$range, (string) ($addressLength * 8)];

        $binarySubnet = @inet_pton($subnet);

        if ($binarySubnet === false || \strlen($binarySubnet) !== $addressLength) {
            return null;
        }

        if (! ctype_digit($prefix)) {
            return null;
        }

        $bits = (int) $prefix;

        return $bits > $addressLength * 8 ? null : [$binarySubnet, $bits];
    }
}
