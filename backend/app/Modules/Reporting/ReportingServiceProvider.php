<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Reporting\Application\Port\EmployeeAttribution;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Application\Port\PeriodReportReader;
use App\Modules\Reporting\Application\Port\PresenceMetrics;
use App\Modules\Reporting\Application\Port\RealtimeConnectionCounter;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Application\Port\ReportExportMetrics;
use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Application\Port\WorkedTimeMetrics;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Policy\LivePresencePolicy;
use App\Modules\Reporting\Http\Policy\PeriodReportPolicy;
use App\Modules\Reporting\Http\Policy\WorkDayJournalPolicy;
use App\Modules\Reporting\Infrastructure\Adapter\BrowsershotReportRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\ReverbConnectionCounter;
use App\Modules\Reporting\Infrastructure\Broadcasting\BroadcastPresenceChange;
use App\Modules\Reporting\Infrastructure\Console\PresenceMetricsCommand;
use App\Modules\Reporting\Infrastructure\Listener\RecordWorkedMinutes;
use App\Modules\Reporting\Infrastructure\Metrics\RedisReportExportMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\RedisWorkedTimeMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\TextfilePresenceMetrics;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseEmployeeAttribution;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseLivePresenceReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabasePeriodReportReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseReportIssuerDirectory;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseWorkDayJournalReader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
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

        $this->registerPeriodReport();
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

        /*
         * El informe por periodo (RF-IN-01, tarea 2.8). `manager+` del Anexo B,
         * que aqui es `{admin, rrhh}`: el `responsable_departamento` no lleva
         * `reports:*` en su token (§7.3) y por tanto ni siquiera pasa del
         * middleware. Las dos comprobaciones dicen lo mismo, que es como tienen
         * que ser.
         */
        Gate::policy(PeriodReport::class, PeriodReportPolicy::class);

        $this->broadcastPresenceChanges();
        $this->recordWorkedMinutes();

        if ($this->app->runningInConsole()) {
            $this->commands([PresenceMetricsCommand::class]);
        }
    }

    /**
     * El informe por periodo y sus dos techos de recursos (RF-IN-01..03, tarea
     * 2.8).
     *
     * **El `statement_timeout` se inyecta desde `config/reporting.php`** y no se
     * lee dentro del adaptador: `Infrastructure` puede hablar con el framework,
     * pero un adaptador que consulta la configuracion por su cuenta es un
     * adaptador que no se puede construir en una prueba con otro techo. Los otros
     * dos limites —rango y filas— los lee el controlador y los pasa al caso de
     * uso, porque `Application` no lee configuracion (doc 02 §3.5).
     */
    private function registerPeriodReport(): void
    {
        $this->app->bind(
            PeriodReportReader::class,
            static fn (Application $app): DatabasePeriodReportReader => new DatabasePeriodReportReader(
                $app->make(ConnectionInterface::class),
                Config::integer('reporting.period.statement_timeout_seconds'),
            ),
        );

        // `worked_minutes_total{site,department}` (§8.2). Redis y no el colector
        // *textfile*: el hecho medido ocurre en cada cambio de turno, no una vez
        // al dia como la metrica de presencia.
        $this->app->bind(WorkedTimeMetrics::class, RedisWorkedTimeMetrics::class);

        // El nombre del departamento para etiquetar esa serie. Puerto propio y de
        // una sola columna: ver su docblock.
        $this->app->bind(EmployeeAttribution::class, DatabaseEmployeeAttribution::class);

        $this->registerPeriodReportExport();
    }

    /**
     * La descarga del informe en CSV, XLSX y PDF (RF-IN-04, tarea 2.9).
     *
     * **Los tres puertos son de esta tarea y ninguno amplia una frontera.** El
     * del motor de PDF existe para que la ausencia de Chromium degrade **un
     * formato** y no la exportacion entera: su adaptador traduce cualquier fallo
     * del proceso externo a una excepcion propia que el borde sirve como `503`
     * con la salida escrita dentro, en vez de un `500` opaco.
     *
     * El del emisor lee `users.name` para sellar el pie del PDF. Es una consulta
     * de una columna sobre la tabla, no un `use` del modelo de `Identity`: la
     * frontera del §1.6 sigue cerrada y `Reporting` sigue siendo un modelo de
     * lectura cuya fuente es la base de datos.
     */
    private function registerPeriodReportExport(): void
    {
        $this->app->bind(ReportDocumentRenderer::class, BrowsershotReportRenderer::class);
        $this->app->bind(ReportIssuerDirectory::class, DatabaseReportIssuerDirectory::class);

        // `report_exports_total{format}` (§8.2). Redis y no el colector
        // *textfile*, como sus hermanas: `HINCRBY` es atomico y dos procesos PHP
        // no pueden pisarse reescribiendo el mismo fichero.
        $this->app->bind(ReportExportMetrics::class, RedisReportExportMetrics::class);
    }

    /**
     * El contador de minutos trabajados, alimentado por el cierre de tramo
     * (§8.2, tarea 2.8).
     *
     * **Encolado y despues del commit**, como la difusion de presencia y al
     * contrario que los listeners de auditoria: este habla con Redis y consulta
     * el departamento, asi que sincrono contaria minutos de un fichaje que
     * todavia puede revertir y meteria dos viajes de red en el camino critico
     * (RNF-P-02, reglas duras 15 y 19).
     *
     * **Solo `EmployeeClockedOut`.** `ShiftCorrected` no entra: un contador solo
     * puede crecer, asi que una anulacion no se puede restar y una correccion
     * sumaria las mismas horas dos veces. La consecuencia —que esta serie no
     * refleja las correcciones— esta escrita en el docblock del puerto para que
     * nadie la use para cuadrar horas: para eso esta `daily_totals`.
     */
    private function recordWorkedMinutes(): void
    {
        Event::listen(EmployeeClockedOut::class, [RecordWorkedMinutes::class, 'handle']);
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
