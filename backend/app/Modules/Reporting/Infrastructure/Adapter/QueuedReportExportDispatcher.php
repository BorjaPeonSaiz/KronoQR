<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Port\ReportExportQueue;
use App\Modules\Reporting\Infrastructure\Job\GenerateReportExportJob;

/**
 * Adaptador de {@see ReportExportQueue} sobre la cola de Laravel (**RF-IN-06**).
 *
 * Existe para que el caso de uso pueda encolar sin importar el framework (doc 02
 * §3.5, verificado por Deptrac) y para que una prueba pueda sustituirlo por un
 * doble que recuerde lo encolado sin levantar Redis.
 *
 * **Cola `default`**, como todo lo demas en este producto: Horizon no tiene
 * configuracion publicada, y una cola `reports` sin proceso que la atienda seria
 * un informe que nunca termina y una fila `pending` hasta que la obsolescencia la
 * mate. Queda anotado como resto de la tarea.
 */
final readonly class QueuedReportExportDispatcher implements ReportExportQueue
{
    public function enqueue(string $uuid): void
    {
        GenerateReportExportJob::dispatch($uuid);
    }
}
