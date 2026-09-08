<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DataExportManifest;

/**
 * Quien compone el `README.md` que va dentro del ZIP (**RF-PD-14**, RL-20).
 *
 * ## Por que el README no es opcional
 *
 * RL-20 no dice «entregale un ZIP»: dice que el cliente pueda **seguir
 * cumpliendo su obligacion de conservacion** aunque la relacion comercial
 * termine. Una carpeta con diecisiete CSV cuyas columnas nadie sabe interpretar
 * no cumple eso; a los dos años, sin el producto delante, `status = superseded`
 * o `clock_in_source = pin_fallback` no significan nada para nadie.
 *
 * Por eso el README explica **cada fichero y cada columna**, dice que los
 * instantes van en UTC y como convertirlos a la zona del centro, y describe la
 * formula con la que se puede verificar la cadena de hash de `audit_log` fuera
 * del producto (RL-04).
 *
 * ## En el idioma de la INSTALACION
 *
 * No en el de la peticion. El fichero lo abrira alguien del hotel dentro de dos
 * años, no el navegador que pulso el boton: el idioma que importa es el que el
 * cliente configuro para su instalacion (`LOCALE_DEFAULT`), igual que el
 * delimitador del CSV. Los textos viven en `lang/{es,en}/data-export.php`.
 *
 * ## Es un puerto y no una funcion suelta
 *
 * Porque componerlo necesita el traductor, que es framework, y el caso de uso no
 * puede tocarlo (§3.5: sin facades en `Application/`).
 */
interface DataExportGuide
{
    /**
     * El `README.md` completo, en Markdown.
     *
     * @param  string  $locale  El idioma de la instalacion.
     */
    public function render(DataExportManifest $manifest, string $locale): string;
}
