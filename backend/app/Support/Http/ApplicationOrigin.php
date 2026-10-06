<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * El origen web de la instalacion, derivado de `APP_URL`, para CORS (RS-09,
 * hallazgo R4-SC-08 de la 2.2.0; ver `config/cors.php`).
 *
 * Un origen es esquema + host + puerto, y se compara como cadena exacta con la
 * cabecera `Origin` del navegador. Por eso se normaliza como la escribe el
 * navegador: esquema y host en minusculas, sin ruta ni barra final, y el puerto
 * SOLO si no es el de serie del esquema (`https://h:443` llega como `https://h`).
 *
 * Nada especifico de un cliente (regla dura 13): sale de la `APP_URL` que el
 * instalador ya escribe en el `.env`. Una URL vacia o sin esquema `http(s)` no
 * produce origen y CORS queda cerrado del todo, que es lo seguro: el origen
 * propio no necesita CORS para hablar con su propia API.
 */
final class ApplicationOrigin
{
    public static function fromUrl(mixed $url): ?string
    {
        $parts = \is_string($url) ? parse_url(trim($url)) : false;

        if (! \is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! \in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $scheme.'://'.$host.self::portSuffix($scheme, $parts['port'] ?? null);
    }

    /**
     * El origen como patron de `allowed_origins_patterns`: el literal escapado y
     * anclado por los dos lados. No es un comodin; ver `config/cors.php` para
     * por que va como patron y no en `allowed_origins`.
     *
     * @return list<string>
     */
    public static function corsPatternsFor(mixed $url): array
    {
        $origin = self::fromUrl($url);

        return $origin === null ? [] : ['#\A'.preg_quote($origin, '#').'\z#'];
    }

    /**
     * El navegador omite el puerto de serie del esquema en `Origin`.
     */
    private static function portSuffix(string $scheme, ?int $port): string
    {
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $port === null || $port === $defaultPort ? '' : ':'.$port;
    }
}
