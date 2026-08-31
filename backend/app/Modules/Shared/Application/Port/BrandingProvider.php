<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\Branding;

/**
 * Entrega la marca de la instalacion ya resuelta (RF-PD-08, regla dura 13).
 *
 * **Vive en `Shared`** (doc 02 §1.6, ADR-025) porque lo consumen varios modulos
 * —`Identity` para la tarjeta, `Compliance` para los informes sellados y la
 * exportacion legal, `Product` para el endpoint que sirve la marca a las tres
 * SPA— y no es regla de negocio de ninguno. Su adaptador es de
 * `Product/Infrastructure/Adapter/`, que es donde estan las tablas.
 *
 * **Por que existe el puerto y no se lee `config('branding.*')` en cada sitio.**
 * Porque la marca deja de ser variable de entorno en la tarea 5.8 y pasa a ser
 * una fila editable desde el panel: con la lectura repartida, ese cambio serian
 * cuatro ficheros de cuatro modulos y el que se olvidara seguiria imprimiendo la
 * marca vieja. Con el puerto es un adaptador.
 *
 * **Sin `forSite()`, a diferencia de los otros dos proveedores.** Hay
 * exactamente un centro por instalacion (ADR-040) y la marca es de la
 * instalacion, no del centro: un parametro que siempre vale lo mismo no
 * discrimina nada y sugiere una marca por centro que el producto no vende.
 */
interface BrandingProvider
{
    /**
     * La marca vigente.
     *
     * **Nunca devuelve `null` ni falla por falta de configuracion**: sin
     * ninguna fila, el catalogo entrega la marca del producto. Una instalacion
     * recien puesta en marcha tiene que poder imprimir tarjetas.
     */
    public function current(): Branding;
}
