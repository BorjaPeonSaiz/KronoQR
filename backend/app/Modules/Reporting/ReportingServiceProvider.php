<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Application\Port\PresenceMetrics;
use App\Modules\Reporting\Application\Port\RealtimeConnectionCounter;
use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Policy\LivePresencePolicy;
use App\Modules\Reporting\Http\Policy\WorkDayJournalPolicy;
use App\Modules\Reporting\Infrastructure\Adapter\ReverbConnectionCounter;
use App\Modules\Reporting\Infrastructure\Broadcasting\BroadcastPresenceChange;
use App\Modules\Reporting\Infrastructure\Console\PresenceMetricsCommand;
use App\Modules\Reporting\Infrastructure\Metrics\TextfilePresenceMetrics;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseLivePresenceReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseWorkDayJournalReader;
use Illuminate\Support\Facades\Event;
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
 * Aqui esta la raiz de composicion del modulo: los puertos de lectura con sus
 * adaptadores, las policies de los recursos que sirven y el mapa evento →
 * difusion de la presencia en vivo.
 *
 * **Las policies se registran contra objetos de valor de DOMINIO** y no contra
 * modelos Eloquent, por lo mismo que en `Workforce`: si la autorizacion se
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

        // RF-PA-01. Dos consultas planas apoyadas en el indice parcial de turnos
        // abiertos (doc 02 §3.2).
        $this->app->bind(LivePresenceReader::class, DatabaseLivePresenceReader::class);

        /*
         * Las metricas de presencia (doc 02 §8.2).
         *
         * Fichero para el colector *textfile* de `node-exporter`, como las de
         * credenciales y las de la cadena de auditoria, y por el mismo motivo:
         * `/metrics` lo expone la tarea 3.1, y hasta entonces quien produce estos
         * numeros es un comando programado que corre y termina.
         */
        $this->app->bind(PresenceMetrics::class, TextfilePresenceMetrics::class);

        // Reverb corre en otro proceso y no expone Prometheus: las conexiones
        // vivas se le preguntan por su API HTTP compatible con Pusher.
        $this->app->bind(RealtimeConnectionCounter::class, ReverbConnectionCounter::class);
    }

    public function boot(): void
    {
        Gate::policy(WorkDayJournal::class, WorkDayJournalPolicy::class);

        /*
         * La misma policy autoriza el endpoint de sondeo y la suscripcion al
         * canal de WebSocket (`routes/channels.php`). Si el canal tuviera la
         * suya, el dia que una de las dos cambiara habria una via en tiempo real
         * hacia datos que el endpoint ya no da (regla dura 18).
         */
        Gate::policy(PresenceBoard::class, LivePresencePolicy::class);

        $this->broadcastPresenceChanges();

        if ($this->app->runningInConsole()) {
            $this->commands([PresenceMetricsCommand::class]);
        }
    }

    /**
     * El mapa evento de dominio → mensaje del panel en vivo (RF-PA-01, ADR-011).
     *
     * **Vive aqui y no en `AttendanceServiceProvider`**, por lo mismo que el mapa
     * de auditoria vive en `Compliance`: el modulo que produce el hecho no tiene
     * que saber quien lo escucha. `Attendance` emite y `Reporting` reacciona
     * (doc 02 §1.6), y el nucleo no sabe que la vista en vivo existe.
     *
     * **Los tres son ENCOLADOS**, al contrario que los de auditoria, y la
     * diferencia es la que marca el docblock de `LaravelEventBus`: aquellos
     * escriben en la misma base de datos y **deben** entrar en la transaccion del
     * fichaje —si el asiento falla, el fichaje no se confirma (regla dura 6)—;
     * este sale por red a otro proceso, asi que difundiria un fichaje que
     * todavia puede revertir y ademas metaria una llamada de red en el camino
     * critico (RNF-P-02, reglas duras 15 y 19).
     *
     * **`ShiftCorrected` esta en la lista y cubre las cuatro acciones**: alta
     * manual, cambio de hora, cierre de un turno olvidado y anulacion. Cualquiera
     * de las cuatro puede cambiar quien esta dentro, y una anulacion que no
     * difundiera dejaria el panel enseñando dentro a alguien cuyo tramo se acaba
     * de anular.
     */
    private function broadcastPresenceChanges(): void
    {
        Event::listen(EmployeeClockedIn::class, [BroadcastPresenceChange::class, 'clockedIn']);
        Event::listen(EmployeeClockedOut::class, [BroadcastPresenceChange::class, 'clockedOut']);
        Event::listen(ShiftCorrected::class, [BroadcastPresenceChange::class, 'corrected']);
    }
}
