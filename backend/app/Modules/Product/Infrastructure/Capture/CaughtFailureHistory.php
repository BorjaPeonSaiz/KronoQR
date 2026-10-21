<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Capture;

use App\Modules\Shared\Application\Port\CaughtFailureReporter;
use App\Support\Resilience\DependencyFailure;
use Throwable;

/**
 * El adaptador de {@see CaughtFailureReporter}: lleva al historico de errores un
 * fallo capturado a proposito, por el mismo {@see ServerErrorReporter} —el
 * mismo filtro de desenlaces esperados, el mismo saneado, la misma huella—
 * que los que se escapan.
 *
 * ## Lo que el manejador de excepciones no informa, aqui tampoco
 *
 * `bootstrap/app.php` descarta las averias que ya constan una sola vez al
 * abrirse su cortacircuitos (`database.circuit_opened`, `redis.circuit_opened`):
 * PostgreSQL inalcanzable o perdido a mitad de camino, y el circuito de Redis
 * abierto. Este puerto no pasa por el manejador, asi que repite el mismo
 * descarte con **el mismo predicado**,
 * {@see DependencyFailure::isOnRecordThroughItsCircuit()}. Sin el, durante una
 * caida cada elemento de cada lote de cincuenta escaneos escribiria su fila, y
 * el historico se convertiria en la segunda averia. Quien captura ya deja su
 * propio apunte con la clase.
 *
 * ## No lanza
 *
 * Ni el filtro ni el informe: lo exige el contrato del puerto.
 */
final readonly class CaughtFailureHistory implements CaughtFailureReporter
{
    public function __construct(private ServerErrorReporter $reporter) {}

    public function report(Throwable $failure): void
    {
        try {
            if (DependencyFailure::isOnRecordThroughItsCircuit($failure)) {
                return;
            }

            $this->reporter->report($failure);
        } catch (Throwable) {
            // Ver el docblock: el puerto no lanza.
        }
    }
}
