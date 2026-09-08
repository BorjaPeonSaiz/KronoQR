<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\TelemetryDelivery;
use App\Modules\Product\Domain\ValueObject\TelemetryReport;

/**
 * **El unico canal saliente del producto** (**RF-PD-12**, ADR-020).
 *
 * ## Por que es un puerto
 *
 * Para que exista un solo sitio en `app/` donde viva un cliente HTTP saliente y
 * para poder decirlo con una prueba: `tests/Architecture/OutboundChannelsTest.php`
 * comprueba que ningun fichero fuera de `Product/Infrastructure/Telemetry/`
 * nombra el cliente HTTP de Laravel, Guzzle ni `curl_*`. Esa prueba **es** la
 * verificacion que ADR-020 pide por escrito: *ningun canal del producto envia
 * datos al fabricante fuera del paquete de diagnostico y de la telemetria*.
 *
 * Sin el puerto, esa afirmacion seria una revision manual del arbol cada vez que
 * alguien añade una integracion.
 *
 * ## No lanza. Nunca.
 *
 * Devuelve {@see TelemetryDelivery}. Ver su docblock: el escenario normal de
 * este producto es una instalacion sin salida a internet, y un fallo de red no
 * puede parecerse a una averia.
 *
 * ## El destino es un argumento
 *
 * Y no configuracion leida dentro del adaptador. Es el mismo criterio que los
 * umbrales legales (regla dura 14) y que la clave publica de la licencia: lo del
 * entorno se resuelve en el borde, en `ProductServiceProvider`, y asi una prueba
 * puede apuntar a un puerto cerrado sin tocar el estado global del proceso.
 */
interface TelemetrySender
{
    /**
     * @param  string  $endpoint  URL absoluta con esquema `https` (o `http` en pruebas).
     */
    public function send(TelemetryReport $report, string $endpoint): TelemetryDelivery;
}
