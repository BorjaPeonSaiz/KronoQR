<?php

declare(strict_types=1);

/*
 * Marca de la instalacion (RF-PD-08, regla dura 13, ADR-017).
 *
 * NADA ESPECIFICO DE UN CLIENTE VIVE EN EL CODIGO. El nombre que aparece en una
 * tarjeta impresa, el logotipo y el color corporativo son de cada cliente, y
 * meterlos en una plantilla Blade obligaria a tocar el repositorio —o, peor, a
 * mantener una rama— para vender la siguiente instalacion.
 *
 * POR QUE ESTE FICHERO EXISTE YA EN LA FASE 1. La tarea 5.8 traslada estos
 * valores a `installation_settings`, para que el cliente los cambie desde el
 * panel sin reiniciar nada. Hasta entonces son variables de entorno. Lo que no
 * podia esperar a la 5.8 es la FORMA: la plantilla de la tarjeta tiene que nacer
 * recibiendo la marca desde fuera, porque una plantilla que la lleva incrustada
 * no se «configura despues», se reescribe.
 *
 * TODOS LOS VALORES SON OPCIONALES Y NINGUNO TIENE UNA MARCA POR DEFECTO. Una
 * tarjeta sin logotipo se imprime perfectamente; una con el logotipo del
 * fabricante metido de serie es un error que se descubre cuando ya hay sesenta
 * impresas.
 */

return [

    /*
     * Nombre que se imprime en la tarjeta. Suele ser el del hotel o el de la
     * cadena. Si esta vacio, la tarjeta lleva el nombre del CENTRO, que sale de
     * `sites.name` y siempre existe.
     */
    'name' => env('BRANDING_NAME'),

    /*
     * Ruta ABSOLUTA en el servidor a un fichero de imagen (PNG o SVG) que se
     * incrusta en el PDF en base64.
     *
     * Absoluta y del sistema de ficheros, no una URL: el PDF se genera en un
     * Chromium sin salida a internet (ADR-016) y una URL remota dejaria la
     * impresion a merced de la red del cliente. Si el fichero no existe, la
     * tarjeta se imprime sin logotipo en lugar de fallar: nadie se queda sin
     * poder fichar porque falte una imagen.
     */
    'logo_path' => env('BRANDING_LOGO_PATH'),

    /*
     * Color de acento de la tarjeta, en notacion CSS (`#0f172a`). Se usa en el
     * filete superior y en el nombre del centro.
     */
    'accent_color' => env('BRANDING_ACCENT_COLOR', '#111827'),

];
