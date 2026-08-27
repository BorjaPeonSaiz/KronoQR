<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Policy\WorkDayJournalPolicy;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseWorkDayJournalReader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Reporting — proyecciones, consultas de lectura y exportaciones
 * (doc 02 §1.6). Depende de Shared y de eventos de otros modulos.
 *
 * daily_totals es una proyeccion reconstruible que se recalcula, nunca se
 * incrementa (regla dura 7, RN-06, ADR-007). Sus listeners llegan con la
 * tarea 1.9.
 *
 * Aqui esta la raiz de composicion del modulo: el puerto de lectura del registro
 * horario con su adaptador, y la policy del recurso que ese puerto sirve.
 *
 * **La policy se registra contra el objeto de valor de DOMINIO** y no contra un
 * modelo Eloquent, por lo mismo que en `Workforce`: si la autorizacion se
 * declarara sobre la fila, habria que cargarla para poder preguntar si se puede
 * leer, y esa es la via por la que la autorizacion acaba ocurriendo despues del
 * acceso a los datos.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // RF-PA-03. El adaptador es SQL plano sobre la conexion —cuatro consultas
        // y ningun N+1—, y vive en Infrastructure/Persistence: `Application` no
        // conoce Eloquent ni la conexion.
        $this->app->bind(WorkDayJournalReader::class, DatabaseWorkDayJournalReader::class);
    }

    public function boot(): void
    {
        Gate::policy(WorkDayJournal::class, WorkDayJournalPolicy::class);
    }
}
