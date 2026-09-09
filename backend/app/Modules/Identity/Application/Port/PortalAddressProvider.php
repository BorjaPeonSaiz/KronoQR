<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * La direccion del portal del empleado de ESTA instalacion (tarea 5.11b, RL-05,
 * ADR-015).
 *
 * ## Por que un puerto para una cadena
 *
 * Porque la alternativa es `env()` o `config()` dentro de un caso de uso, y eso
 * es exactamente lo que la direccion de dependencias prohibe: `Application` no
 * sabe que existe un `.env`. La direccion se compone en `Infrastructure`
 * —`APP_URL` mas el prefijo con el que Nginx sirve la SPA del portal, ver
 * `infra/docker/nginx/extra/spa.conf`— y llega aqui hecha.
 *
 * ## Por que no es un ajuste de `installation_settings`
 *
 * Porque no es una eleccion del cliente: es **donde responde su propio
 * servidor**. Un ajuste editable permitiria imprimir cuarenta hojas con una
 * direccion en la que no hay nada, y el sintoma seria una plantilla entera que
 * no puede consultar su registro (RL-05). Si algun dia el portal se publicara en
 * otro dominio, ese dato ya seria configuracion y este puerto es donde se
 * enchufa sin tocar la hoja.
 *
 * ## Nunca vacia
 *
 * Sin `APP_URL` util, el adaptador entrega la direccion relativa del portal
 * antes que una cadena en blanco: una hoja que dice `/portal/` sigue sirviendo
 * —quien la lee tiene la intranet del hotel delante— y una hoja con un hueco en
 * blanco no sirve de nada.
 */
interface PortalAddressProvider
{
    /**
     * @return non-empty-string
     */
    public function current(): string;
}
