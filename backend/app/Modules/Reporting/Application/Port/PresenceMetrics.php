<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use DateTimeImmutable;

/**
 * Las dos metricas de la presencia en vivo del doc 02 §8.2.
 *
 * ```
 * open_shifts_current{site,site_name,department}   gauge
 * websocket_connections_active                     gauge
 * ```
 *
 * **La primera es ademas la metrica de negocio «Turnos abiertos en este
 * momento» del doc 01 §9.2**, y es la unica de las dos que dice algo sobre el
 * producto: cuanta gente hay trabajando. La segunda dice algo sobre la
 * infraestructura —cuantos paneles siguen recibiendo tiempo real— y su valor
 * real es distinguir «el WebSocket esta caido» de «el sistema esta caido»
 * (ADR-011), que no son lo mismo y el segundo es mucho mas grave.
 *
 * **Puerto y no un escritor concreto**, por lo mismo que
 * `Attendance\Application\Port\ScanMetrics`: el caso de uso no sabe si detras
 * hay un fichero para el colector *textfile* de `node-exporter`, un registro de
 * `promphp` o nada. Hoy es lo primero —`/metrics` lo expone la tarea 3.1— y esa
 * eleccion no puede filtrarse a quien produce los numeros.
 *
 * **Las dos son gauges y se PUBLICAN enteras, nunca se incrementan** (regla dura
 * 7 aplicada a la instrumentacion). Un contador que subiera con cada entrada y
 * bajara con cada salida se desviaria en el primer mensaje perdido y nadie lo
 * notaria; un recuento completo cada minuto es correcto por construccion, y
 * ademas recoge lo que no pasa por un fichaje: una anulacion, una correccion que
 * cierra un turno olvidado, una carga inicial.
 *
 * **Ninguna de las dos puede tumbar nada.** Publicarlas es trabajo de una tarea
 * programada; si falla, falla la tarea y se ve en su log y en la ausencia de la
 * serie. El camino de fichaje no las toca (regla dura 19).
 */
interface PresenceMetrics
{
    /**
     * @param  array<string, int>  $openShiftsByDepartment  Nombre de departamento => turnos abiertos.
     *                                                      La cadena vacia agrupa a quien no tiene departamento.
     * @param  int|null  $websocketConnections  Conexiones vivas al WebSocket, o `null` si no se ha podido
     *                                          preguntar. **Nulo no es cero**: cero significa «nadie tiene el
     *                                          panel abierto» y nulo significa «no se sabe», y publicarlos
     *                                          igual convertiria una averia de Reverb en una jornada tranquila.
     */
    public function publish(
        int $siteId,
        string $siteName,
        array $openShiftsByDepartment,
        ?int $websocketConnections,
        DateTimeImmutable $at,
    ): void;
}
