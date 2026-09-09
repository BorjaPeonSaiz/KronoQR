<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\PortalAddressProvider;

/**
 * La direccion del portal del empleado: `APP_URL` mas el prefijo con el que
 * Nginx sirve su SPA (tarea 5.11b, RL-05).
 *
 * ## De donde sale el `/portal/`
 *
 * De `infra/docker/nginx/extra/spa.conf`, que publica las tres SPA bajo
 * `^~ /admin/`, `^~ /kiosk/` y `^~ /portal/` del mismo dominio. **No es una
 * eleccion de este adaptador**: es donde responde el servidor del cliente, y por
 * eso esta escrito una sola vez y aqui.
 *
 * La barra final no es decorativa: sin ella, la `location ^~ /portal/` de Nginx
 * no casa y quien teclee la direccion de la hoja recibe un 404 del panel.
 *
 * ## Que pasa si `APP_URL` no vale
 *
 * Se entrega la direccion **relativa** (`/portal/`). Un `APP_URL` mal puesto es
 * un descuido de despliegue, y la reaccion util es imprimir una hoja que sigue
 * diciendo donde esta el portal para quien tiene la intranet del hotel delante,
 * no una hoja con un hueco en blanco ni una excepcion que deje a RRHH sin poder
 * entregar tarjetas (regla dura 19, el mismo criterio que el puerto del
 * logotipo de la marca, `Shared\Application\Port\BrandingLogoReader`, nombrado
 * en prosa para no arrastrar aqui un `use` que solo lee un docblock).
 *
 * Se exige `http`/`https` a proposito: un `APP_URL` con otro esquema —o con
 * texto que no es una URL— impreso en cuarenta hojas es peor que la relativa.
 */
final readonly class ConfiguredPortalAddress implements PortalAddressProvider
{
    /**
     * El prefijo de la SPA del portal en el borde. Ver el docblock de la clase.
     */
    private const string PORTAL_PATH = '/portal/';

    public function __construct(private string $applicationUrl) {}

    public function current(): string
    {
        $base = rtrim(trim($this->applicationUrl), '/');

        if ($base === '') {
            return self::PORTAL_PATH;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);

        if (! \in_array($scheme, ['http', 'https'], true) || ! \is_string($host) || $host === '') {
            return self::PORTAL_PATH;
        }

        return $base.self::PORTAL_PATH;
    }
}
