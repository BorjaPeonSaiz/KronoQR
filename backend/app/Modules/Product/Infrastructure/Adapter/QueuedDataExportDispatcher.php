<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\DataExportQueue;
use App\Modules\Product\Infrastructure\Job\GenerateDataExportJob;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Pone {@see GenerateDataExportJob} en la cola (**RF-PD-14**).
 *
 * Adaptador de {@see DataExportQueue}: existe para que el caso de uso pueda
 * encolar sin importar el framework (§3.5, verificado por Deptrac) y para que una
 * prueba pueda sustituirlo por un doble que recuerde lo que se encolo, sin
 * levantar un trabajador.
 *
 * **Sin `afterCommit`**, al contrario de lo que suele hacer falta. El caso de uso
 * llama a este puerto **despues** de confirmar la transaccion, precisamente para
 * que un trabajador rapido no llegue antes que el `COMMIT` y no encuentre la
 * fila. Poner ademas `afterCommit` no haria daño, pero seria una segunda defensa
 * del mismo problema en un sitio donde ya no hay transaccion abierta: parecerian
 * dos mecanismos y solo hay uno.
 */
final readonly class QueuedDataExportDispatcher implements DataExportQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function enqueue(string $uuid): void
    {
        $this->bus->dispatch(new GenerateDataExportJob($uuid));
    }
}
