<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * `pin_resets_total{site}` (doc 02 §8.2, tarea 1.13).
 *
 * **Que dice esta metrica.** Una subida sostenida de restablecimientos no es un
 * problema tecnico: es que los PIN no llegan a la gente, o que nadie ha
 * explicado para que sirven. Se mira junto a `pin_fallback_scans_total` de la
 * tarea 1.12 —las dos suben a la vez cuando la entrega de tarjetas falla— y
 * junto al panel de credenciales sin entregar (RF-QR-08).
 *
 * **La etiqueta es el centro y nunca el empleado** (regla dura 21, RGPD). Una
 * serie temporal por persona seria un registro paralelo de a quien se le olvida
 * su PIN, sin retencion ni control de acceso. La cardinalidad de `site` es la de
 * los hoteles de la instalacion: unidades.
 *
 * Es un puerto y no una llamada directa por lo mismo que
 * `Attendance\Application\Port\ScanMetrics` —nombrado en prosa y no con `@see`,
 * porque una referencia resoluble seria una dependencia entre modulos que la
 * frontera del §1.6 no concede—: quien mide no
 * sabe si detras hay Redis, un fichero para el colector *textfile* o nada, y en
 * las pruebas es un doble que cuenta.
 */
interface PinMetrics
{
    /**
     * Un PIN restablecido en el centro indicado.
     *
     * Solo el restablecimiento, no la emision del alta: un alta ya se cuenta
     * como alta, y sumar las dos cosas en el mismo contador haria que una
     * temporada de contrataciones se pareciera a un problema de entrega.
     */
    public function pinReset(int $siteId): void;
}
