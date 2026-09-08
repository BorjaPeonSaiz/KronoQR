<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\SupportAccessRecorder;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * La ventana de auditoria de los usos de un acceso de soporte (**RF-PD-11**).
 *
 * ## `add()` y no `has()` + `put()`
 *
 * `add()` es atomico en Redis: escribe **solo si la clave no existe** y dice si
 * la escribio. Con dos peticiones simultaneas de la misma sesion de soporte
 * —cosa normal: un panel abre varias a la vez— una comprobacion previa dejaria
 * pasar a las dos y escribiria dos asientos del mismo hecho. Es el mismo motivo
 * por el que la idempotencia del fichaje se resuelve con el `UNIQUE` de
 * `scan_events.scan_id` y no con un `SELECT` previo.
 *
 * ## Falla ABIERTO, y es la unica vez que este modulo lo hace
 *
 * Si la cache no responde, se audita. El coste de equivocarse es un asiento de
 * mas —una fila en una tabla que ya guarda millones—; el de fallar cerrado seria
 * un acceso del fabricante sin rastro, que es exactamente lo que ADR-020 existe
 * para impedir. Ante la duda, se escribe.
 *
 * ## Misma cache que el resto y sin prefijo propio
 *
 * `CACHE_STORE` es Redis en cualquier instalacion real (doc 02 §3). Si un
 * despliegue lo tuviera en `array` —desarrollo, pruebas—, la ventana duraria lo
 * que la peticion y se auditaria cada uso: mas ruido, nunca menos rastro.
 */
final readonly class CacheSupportAccessRecorder implements SupportAccessRecorder
{
    public function __construct(private CacheRepository $cache) {}

    public function shouldRecord(int $grantId, int $windowSeconds): bool
    {
        if ($windowSeconds <= 0) {
            // Agrupacion desactivada: un asiento por peticion. Solo tiene sentido
            // mientras se depura algo.
            return true;
        }

        try {
            return $this->cache->add('product:support-use:'.$grantId, true, $windowSeconds);
        } catch (Throwable) {
            // Ver el docblock: ante la duda, se audita.
            return true;
        }
    }
}
