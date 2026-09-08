<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\LogoImage;

/**
 * Lee el logotipo del disco del cliente (RF-PD-08, tarea 5.8).
 *
 * Adaptador de {@see BrandingLogoReader}. Compone dos cosas que ya existen: la
 * ruta, que sale de `installation_settings` por {@see BrandingProvider}, y la
 * comprobacion, que es la misma que aplica el `PATCH` al guardarla
 * ({@see LogoInspector}). **Aqui no hay ninguna regla nueva**, y esa es la
 * intencion: si esta clase decidiera algo por su cuenta, habria un fichero que
 * el panel acepta y el endpoint publico no sirve.
 *
 * ## Tolerante, siempre
 *
 * Sin ruta configurada, con el fichero borrado, con el volumen de marca sin
 * montar o con un fichero que ya no pasa la comprobacion: `null`. Las cuatro
 * cosas significan lo mismo para quien dibuja —**se sigue sin logotipo**— y
 * ninguna puede impedir imprimir una tarjeta ni cargar la pantalla del quiosco
 * (regla dura 19).
 *
 * **Y no avisa por su cuenta.** Se penso registrar un aviso cuando la ruta esta
 * configurada y el fichero no aparece, y se descarto: esto se llama en cada
 * pantalla del quiosco y en cada documento, asi que un aviso por lectura serian
 * miles al dia por una imagen que falta. Quien tiene que decirlo es `doctor`
 * (tarea 5.9), que corre cuando alguien esta buscando el problema.
 *
 * ## Memoria por peticion
 *
 * Un informe con logotipo lo pide una vez por documento, y la pantalla que lo
 * enseña una vez por peticion; pero el `null` tambien se memoriza, que es lo que
 * evita volver a golpear el disco en cada intento cuando el fichero **no** esta.
 * `scoped()` en el proveedor: un cambio guardado en el panel se ve en la
 * peticion siguiente, nunca hace falta reiniciar.
 */
final class LocalBrandingLogoReader implements BrandingLogoReader
{
    private bool $resolved = false;

    private ?LogoImage $logo = null;

    public function __construct(
        private readonly BrandingProvider $branding,
        private readonly LogoInspector $inspector,
    ) {}

    public function current(): ?LogoImage
    {
        if ($this->resolved) {
            return $this->logo;
        }

        $this->resolved = true;

        $path = $this->branding->current()->logoPath;

        if ($path === null) {
            // La ruta vacia significa «el logotipo del producto» y no llega
            // hasta aqui como cadena: el proveedor ya la tradujo a `null`.
            return $this->logo = null;
        }

        $inspection = $this->inspector->inspect($path);

        return $this->logo = $inspection->image;
    }
}
