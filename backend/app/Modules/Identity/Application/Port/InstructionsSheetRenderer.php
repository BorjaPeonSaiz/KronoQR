<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Shared\Domain\ValueObject\Branding;

/**
 * Convierte la hoja de instrucciones del empleado en un PDF (tarea 5.11b,
 * **RL-05**, ficha 5.11b decision 1).
 *
 * **Es un puerto por lo mismo que {@see CardRenderer}**: detras hay un proceso
 * externo. El adaptador de produccion compone el documento con
 * `spatie/laravel-pdf`, que arranca un Chromium; con eso dentro del caso de uso,
 * la resolucion del idioma y de la direccion del portal —que si son decisiones
 * del producto— no se podrian probar sin un navegador instalado.
 *
 * **Recibe las tres cosas ya resueltas y no resuelve ninguna.** El idioma sale
 * de `LOCALE_AVAILABLE`/`LOCALE_DEFAULT`, la marca del puerto `BrandingProvider`
 * y la direccion del portal de la instalacion: las tres las decide el caso de
 * uso, para que cambiar de dibujante no cambie lo que dice la hoja.
 *
 * **Devuelve los bytes, no una ruta**, como el de las tarjetas — pero por un
 * motivo distinto y mas debil: aqui no hay ningun secreto (es la misma hoja para
 * toda la plantilla y no lleva ningun dato personal). Lo que se evita es un
 * fichero mas que caduca en `storage/` con la marca y la direccion de ayer.
 *
 * **Un documento de una sola cara.** Lo exige la ficha —la hoja se entrega con
 * la tarjeta, no se encuaderna— y lo comprueba
 * `Tests\Integration\Identity\InstructionsSheetLayoutTest` sobre el PDF de
 * verdad, contando las paginas.
 */
interface InstructionsSheetRenderer
{
    /**
     * @param  string  $locale  Uno de los idiomas activos de la instalacion, ya
     *                          resuelto por el caso de uso contra `LOCALE_AVAILABLE`:
     *                          aqui no llega vacio ni llega uno que la instalacion
     *                          no ofrezca.
     * @param  string  $portalUrl  Direccion del portal del empleado de ESTA instalacion.
     * @return string Los bytes del PDF.
     */
    public function render(string $locale, Branding $brand, string $portalUrl): string;
}
