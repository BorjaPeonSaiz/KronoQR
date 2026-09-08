<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SupportAccessRecorder;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Domain\Event\SupportAccessUsed;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Anota que un acceso de soporte se ha usado (**RF-PD-11**, ADR-020, regla dura
 * 6).
 *
 * ## Dos escrituras con dos frecuencias distintas, y es deliberado
 *
 * - **`accessed_at`, en cada peticion.** Es un `UPDATE` de una columna, sin
 *   candado y sin cadena de hash. Es lo que hace que el panel diga «usado hace
 *   dos minutos» —la mitad visible para el cliente de RF-PD-11— en lugar de
 *   «usado hace un cuarto de hora».
 * - **El asiento, una vez por ventana.** `audit_log` es solo-apendice y
 *   encadenado bajo un candado global (ADR-010): el mismo por el que pasa cada
 *   fichaje. Una sesion de soporte de veinte minutos con un asiento por peticion
 *   serian cientos de escrituras en esa cadena, y la regla dura 19 dice que nada
 *   degrada el camino del quiosco. Misma palanca que ADR-037 con las lecturas de
 *   datos personales: agrupar por frecuencia sin quitar el aviso.
 *
 * ## Nunca puede tumbar la peticion que describe
 *
 * Quien llama es un middleware que corre **despues** de que la respuesta este
 * hecha, y envuelve esta llamada. La excepcion a la regla dura 6 esta razonada:
 * el asiento de un uso no es la prueba de una potestad —esa es la concesion, que
 * si es sincrona y transaccional— sino su rastro de ejercicio, y perder un
 * rastro por un fallo de base de datos es preferible a devolverle un `500` a
 * quien esta intentando arreglar una incidencia. El fallo queda en el log
 * tecnico.
 *
 * ## No lee el estado de la concesion
 *
 * No comprueba si sigue activa, y no debe: si la peticion llego hasta aqui es
 * porque `Identity` ya acepto el token, que es donde vive esa comprobacion.
 * Repetirla seria una segunda fuente de verdad sobre si un acceso vale.
 */
final readonly class RecordSupportAccessUseHandler
{
    public function __construct(
        private SupportGrantRepository $grants,
        private SupportAccessRecorder $recorder,
        private ProductEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private int $windowSeconds,
    ) {}

    /**
     * @param  string  $route  El PATRON de la ruta (`api/v1/employees/{uuid}`),
     *                         nunca la URL concreta: un UUID de empleado escrito
     *                         en `audit_log.payload` seria un dato personal en un
     *                         sitio que se exporta (regla dura 21).
     */
    public function handle(int $grantId, string $grantUuid, string $scope, string $method, string $route): void
    {
        $now = $this->clock->now();

        $this->grants->markAccessed($grantId, $now);

        if (! $this->recorder->shouldRecord($grantId, $this->windowSeconds)) {
            return;
        }

        // El asiento si va en transaccion: la cadena de hash de `audit_log` no
        // admite escrituras a medias (ADR-010).
        $this->connection->transaction(function () use ($grantId, $grantUuid, $scope, $method, $route, $now): void {
            $this->events->publish(new SupportAccessUsed(
                grantId: $grantId,
                grantUuid: $grantUuid,
                scope: $scope,
                method: $method,
                route: $route,
                occurredAt: $now,
            ));
        });
    }
}
