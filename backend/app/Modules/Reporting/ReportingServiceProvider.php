<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Reporting\Application\Port\AbsenceCensusReader;
use App\Modules\Reporting\Application\Port\AbsenceMetrics;
use App\Modules\Reporting\Application\Port\AdoptionMetrics;
use App\Modules\Reporting\Application\Port\ComplianceFactsReader;
use App\Modules\Reporting\Application\Port\ComplianceIncidentLinks;
use App\Modules\Reporting\Application\Port\ComplianceMetrics;
use App\Modules\Reporting\Application\Port\ComplianceProfileReference;
use App\Modules\Reporting\Application\Port\EmployeeAttribution;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Application\Port\PeriodReportReader;
use App\Modules\Reporting\Application\Port\PresenceMetrics;
use App\Modules\Reporting\Application\Port\QueuedJobFailureMetrics;
use App\Modules\Reporting\Application\Port\RealtimeConnectionCounter;
use App\Modules\Reporting\Application\Port\ReportCriteriaNarrator;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Application\Port\ReportExportDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportExportMetrics;
use App\Modules\Reporting\Application\Port\ReportExportNotifier;
use App\Modules\Reporting\Application\Port\ReportExportQueue;
use App\Modules\Reporting\Application\Port\ReportExportRecipients;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Application\Port\WorkedTimeMetrics;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
use App\Modules\Reporting\Application\UseCase\RequestReportExport;
use App\Modules\Reporting\Application\UseCase\ShowReportExport;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummary;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Policy\ComplianceSummaryPolicy;
use App\Modules\Reporting\Http\Policy\LivePresencePolicy;
use App\Modules\Reporting\Http\Policy\PayrollExportPolicy;
use App\Modules\Reporting\Http\Policy\PeriodReportPolicy;
use App\Modules\Reporting\Http\Policy\ReportExportPolicy;
use App\Modules\Reporting\Http\Policy\WorkDayJournalPolicy;
use App\Modules\Reporting\Infrastructure\Adapter\BrowsershotReportRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\FilesystemReportExportStorage;
use App\Modules\Reporting\Infrastructure\Adapter\LaravelReportingEventPublisher;
use App\Modules\Reporting\Infrastructure\Adapter\QueuedReportExportDispatcher;
use App\Modules\Reporting\Infrastructure\Adapter\ReverbConnectionCounter;
use App\Modules\Reporting\Infrastructure\Broadcasting\BroadcastPresenceChange;
use App\Modules\Reporting\Infrastructure\Console\AbsenceMetricsCommand;
use App\Modules\Reporting\Infrastructure\Console\AdoptionMetricsCommand;
use App\Modules\Reporting\Infrastructure\Console\ComplianceMetricsCommand;
use App\Modules\Reporting\Infrastructure\Console\PresenceMetricsCommand;
use App\Modules\Reporting\Infrastructure\Console\PurgeExpiredReportExportsCommand;
use App\Modules\Reporting\Infrastructure\Export\ConfigurablePayrollDocumentWriter;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportCriteriaNarrator;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportDocumentWriters;
use App\Modules\Reporting\Infrastructure\Listener\RecordWorkedMinutes;
use App\Modules\Reporting\Infrastructure\Metrics\RedisQueuedJobFailureMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\RedisReportExportMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\RedisWorkedTimeMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\TextfileAbsenceMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\TextfileAdoptionMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\TextfileComplianceMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\TextfilePresenceMetrics;
use App\Modules\Reporting\Infrastructure\Notification\MailReportExportNotifier;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseAbsenceCensusReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseComplianceFactsReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseComplianceIncidentLinks;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseComplianceProfileReference;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseEmployeeAttribution;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseLivePresenceReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabasePeriodReportReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseReportExportRecipients;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseReportExportRepository;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseReportIssuerDirectory;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseWorkDayCompletionReader;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseWorkDayJournalReader;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        /*
         * `workdays_complete_ratio{site}` (doc 02 §8.2, RF-IN-08, tarea 3.1).
         *
         * Tambien por fichero, y por una razon propia ademas de la de arriba: es
         * un RATIO que se recalcula entero desde los datos cada noche (regla
         * dura 7 aplicada a la instrumentacion), no un contador que se
         * incrementa. Ver el docblock de `TextfileAdoptionMetrics`.
         */
        $this->app->bind(WorkDayCompletionReader::class, DatabaseWorkDayCompletionReader::class);
        $this->app->bind(AdoptionMetrics::class, TextfileAdoptionMetrics::class);

        // Reverb corre en otro proceso y no expone Prometheus: las conexiones
        // vivas se le preguntan por su API HTTP compatible con Pusher.
        $this->app->bind(RealtimeConnectionCounter::class, ReverbConnectionCounter::class);

        /*
         * `absences_current{type}` (doc 02 §8.2, RF-GP-04, tarea 3.10).
         *
         * Fichero para el colector *textfile*, con el mismo patron que las de
         * cumplimiento y adopcion: es un gauge que un comando programado
         * recalcula entero cada noche (regla dura 7 aplicada a la
         * instrumentacion). El lector es SQL plano sobre la conexion —una
         * consulta agregada, sin Eloquent y sin `N+1`—.
         */
        $this->app->bind(AbsenceCensusReader::class, DatabaseAbsenceCensusReader::class);
        $this->app->bind(AbsenceMetrics::class, TextfileAbsenceMetrics::class);

        $this->registerPeriodReport();
        $this->registerComplianceSummary();
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

        /*
         * La salida a nomina (RF-IN-07, tarea 3.9). «rrhh+» del Anexo B, que aqui
         * vuelve a ser `{admin, rrhh}` — y aun asi es una policy propia y no la
         * de arriba: el dia que un responsable pueda ver las horas de su equipo,
         * esa concesion no puede arrastrar consigo el fichero con el que se paga.
         * Ver {@see PayrollExportPolicy}.
         *
         * Se registra contra la PLANTILLA y no contra el informe, que es el tipo
         * propio de esta salida: dos policies sobre la misma clase habria que
         * distinguirlas por el nombre de la habilidad, que es como se confunden.
         */
        Gate::policy(PayrollLayout::class, PayrollExportPolicy::class);

        /*
         * La vista de cumplimiento (RF-PA-06, tarea 3.4). «manager+» del Anexo B,
         * que aqui es `{admin, rrhh, responsable_departamento}` —el mismo conjunto
         * que la presencia y que la bandeja de incidencias, porque esta pantalla
         * es lo que un responsable mira despues de aquella—. El `auditor` queda
         * fuera teniendo el ambito, que es la mitad que aporta la policy.
         */
        Gate::policy(ComplianceSummary::class, ComplianceSummaryPolicy::class);

        /*
         * El informe generado en diferido (RF-IN-06, RF-IN-07, tarea 3.9).
         *
         * `{admin, rrhh}` para las dos clases de informe, que es lo mismo que
         * pide el sincrono y lo que el Anexo B llama «rol rrhh» para la nomina.
         * El `responsable_departamento` no llega: no lleva `reports:*` en su
         * token (§7.3). Ver `ReportExportPolicy`, que explica por que `request` y
         * `requestPayroll` son dos metodos aunque hoy coincidan.
         *
         * **No autoriza la descarga**: aquella va sin sesion y la autoriza un
         * token de un solo uso (ADR-041). Lo que estas policies protegen es pedir
         * el informe y pedir el enlace.
         */
        Gate::policy(ReportExport::class, ReportExportPolicy::class);

        $this->broadcastPresenceChanges();
        $this->recordWorkedMinutes();
        $this->limitReportDownloads();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AbsenceMetricsCommand::class,
                AdoptionMetricsCommand::class,
                ComplianceMetricsCommand::class,
                PresenceMetricsCommand::class,
                PurgeExpiredReportExportsCommand::class,
            ]);
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
     * La vista de cumplimiento y sus dos metricas (RF-PA-06, tarea 3.4).
     *
     * **El `statement_timeout` se inyecta desde `config/reporting.php`** por lo
     * mismo que en el informe: `Infrastructure` puede hablar con el framework,
     * pero un adaptador que consulta la configuracion por su cuenta es un
     * adaptador que no se puede construir en una prueba con otro techo. El techo
     * del rango lo lee el controlador y lo pasa al caso de uso, porque
     * `Application` no lee configuracion (doc 02 §3.5).
     *
     * **Los tres adaptadores son SQL plano sobre la conexion** y viven en
     * `Infrastructure/Persistence`: ni un modelo Eloquent y ningun `N+1` —una
     * consulta para los hechos, una para el perfil y una para todo el lote de
     * incidencias—.
     */
    private function registerComplianceSummary(): void
    {
        $this->app->bind(
            ComplianceFactsReader::class,
            static fn (Application $app): DatabaseComplianceFactsReader => new DatabaseComplianceFactsReader(
                $app->make(ConnectionInterface::class),
                Config::integer('reporting.compliance.statement_timeout_seconds'),
            ),
        );

        $this->app->bind(ComplianceProfileReference::class, DatabaseComplianceProfileReference::class);
        $this->app->bind(ComplianceIncidentLinks::class, DatabaseComplianceIncidentLinks::class);

        /*
         * `compliance_findings_last_week{rule}` y
         * `compliance_employees_affected_last_week` (doc 02 §8.2).
         *
         * Fichero para el colector *textfile*, como las de presencia y adopcion:
         * son gauges que recalcula entero un comando programado que corre y
         * termina (regla dura 7 aplicada a la instrumentacion). Un contador en
         * Redis no se podria corregir cuando una correccion de jornada deshace el
         * hallazgo, porque solo puede crecer.
         */
        $this->app->bind(ComplianceMetrics::class, TextfileComplianceMetrics::class);
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

        /*
         * Los criterios de inclusion ya traducidos (RF-IN-06, RF-IN-07).
         *
         * UNA SOLA FUENTE para los tres sitios donde se leen: el bloque de
         * cabecera del informe por periodo, la cabecera
         * `X-Kronoqr-Export-Criteria` de la salida a nomina —cuyo fichero no los
         * lleva dentro, decision 5 de la ficha 3.9— y la columna `criteria` de la
         * exportacion en diferido. Con tres composiciones distintas, el fichero y
         * la pantalla acabarian diciendo cosas distintas sobre el mismo informe.
         */
        $this->app->bind(ReportCriteriaNarrator::class, PeriodReportCriteriaNarrator::class);

        $this->registerDeferredReportExports();
    }

    /**
     * Los informes generados **en diferido** (RF-IN-06, RF-IN-07, ADR-041,
     * tarea 3.9).
     *
     * ## Todo lo que se construye a mano se construye por lo mismo
     *
     * Ninguno de estos cuatro objetos puede resolverse solo, y en los cuatro el
     * motivo es el mismo: **`Application` no lee configuracion** (doc 02 §3.5,
     * regla dura 14). El plazo de retencion, el minuto de caducidad del enlace,
     * el umbral de obsolescencia y el `MAIL_MAILER` entran ya resueltos por quien
     * construye, y eso es ademas lo que permite que una prueba fije «cero
     * minutos» o «un segundo» sin tocar el estado global del proceso.
     *
     * ## El lector del diferido es OTRA instancia, y esa es la clave
     *
     * `GenerateReportExportHandler` recibe un {@see GeneratePeriodReport}
     * construido sobre un {@see DatabasePeriodReportReader} con
     * `reporting.export.statement_timeout_seconds` —diez minutos— en lugar de los
     * diez segundos del sincrono. **La consulta sincrona conserva el suyo
     * intacto**: son dos instancias del mismo adaptador con dos techos, no un
     * techo que cambia segun quien llame. Si fuera lo segundo, una peticion
     * concurrente del panel heredaria el techo del trabajo en cola.
     *
     * Los otros dos techos sincronos —rango y filas— los quita el propio caso de
     * uso pasando `PHP_INT_MAX`: no son del adaptador.
     *
     * ## La nomina entra por un puerto aparte
     *
     * {@see PayrollDocumentWriter} es la costura con la plantilla configurable de
     * RF-IN-07, y la sirve {@see ConfigurablePayrollDocumentWriter} con **los
     * mismos escritores** que la descarga sincrona: con dos implementaciones, el
     * fichero que RRHH descarga desde la pantalla y el que llega por el enlace del
     * informe en diferido podrian llevar columnas distintas, y una exportacion de
     * nomina equivocada no se descubre hasta la nomina siguiente.
     *
     * **Una sola implementacion y ninguna de reserva.** Si la plantilla no se
     * puede resolver —una instalacion sin centro—, el adaptador **falla en voz
     * alta**: caer a la disposicion por omision produciria un fichero con otras
     * columnas de las configuradas, que alguien importaria en la herramienta de
     * nomina sin notarlo.
     */
    private function registerDeferredReportExports(): void
    {
        $this->app->bind(
            ReportExportRepository::class,
            static fn (Application $app): DatabaseReportExportRepository => new DatabaseReportExportRepository(
                $app->make(ConnectionInterface::class),
                $app->make(Clock::class),
            ),
        );

        $this->app->bind(ReportExportQueue::class, QueuedReportExportDispatcher::class);
        $this->app->bind(ReportingEventPublisher::class, LaravelReportingEventPublisher::class);
        $this->app->bind(ReportExportDocumentWriter::class, PeriodReportDocumentWriters::class);
        $this->app->bind(PayrollDocumentWriter::class, ConfigurablePayrollDocumentWriter::class);
        $this->app->bind(ReportExportRecipients::class, DatabaseReportExportRecipients::class);

        $this->app->bind(
            ReportExportStorage::class,
            static fn (): FilesystemReportExportStorage => new FilesystemReportExportStorage(
                Config::string('reporting.export.path'),
            ),
        );

        $this->app->bind(
            ReportExportNotifier::class,
            static fn (Application $app): MailReportExportNotifier => new MailReportExportNotifier(
                $app->make(ReportExportRecipients::class),
                Config::string('mail.default'),
            ),
        );

        $this->app->bind(
            RequestReportExport::class,
            static fn (Application $app): RequestReportExport => new RequestReportExport(
                $app->make(ReportExportRepository::class),
                $app->make(ReportExportQueue::class),
                $app->make(ReportingEventPublisher::class),
                $app->make(Clock::class),
                $app->make(ConnectionInterface::class),
                Config::integer('reporting.export.stale_after_seconds'),
            ),
        );

        $this->app->bind(
            ShowReportExport::class,
            static fn (Application $app): ShowReportExport => new ShowReportExport(
                $app->make(ReportExportRepository::class),
                $app->make(Clock::class),
                $app->make(ConnectionInterface::class),
                Config::integer('reporting.export.link_ttl_minutes'),
            ),
        );

        /*
         * `queue_jobs_failed_total{job}` para el trabajo que **captura** su
         * excepcion. Ver el puerto: sin esto, una generacion fallida dejaba la
         * fila en `failed` y no movia ninguna serie, que es justo el caso que la
         * alerta de cola existe para ver.
         */
        $this->app->bind(QueuedJobFailureMetrics::class, RedisQueuedJobFailureMetrics::class);

        $this->app->bind(
            PurgeExpiredReportExports::class,
            static fn (Application $app): PurgeExpiredReportExports => new PurgeExpiredReportExports(
                $app->make(ReportExportRepository::class),
                $app->make(ReportExportStorage::class),
                $app->make(Clock::class),
                Config::integer('reporting.export.stale_after_seconds'),
            ),
        );

        $this->app->bind(
            GenerateReportExportHandler::class,
            fn (Application $app): GenerateReportExportHandler => new GenerateReportExportHandler(
                $app->make(ReportExportRepository::class),
                $this->deferredPeriodReport($app),
                $app->make(ReportExportDocumentWriter::class),
                $app->make(PayrollDocumentWriter::class),
                $app->make(ReportCriteriaNarrator::class),
                $app->make(ReportExportStorage::class),
                $app->make(ReportExportNotifier::class),
                $app->make(ReportingEventPublisher::class),
                $app->make(Clock::class),
                $app->make(ConnectionInterface::class),
                Config::integer('reporting.export.retention_days'),
            ),
        );
    }

    /**
     * El mismo informe del panel, con el `statement_timeout` del diferido.
     *
     * Se compone a mano —en lugar de resolver `GeneratePeriodReport` del
     * contenedor— **solo** para sustituir el lector: todos los demas
     * colaboradores son los que ya usa la consulta sincrona, y eso es lo que
     * garantiza que el fichero y la pantalla digan lo mismo (criterios, festivos,
     * zona horaria y asiento de divulgacion incluidos).
     */
    private function deferredPeriodReport(Application $app): GeneratePeriodReport
    {
        return new GeneratePeriodReport(
            new DatabasePeriodReportReader(
                $app->make(ConnectionInterface::class),
                Config::integer('reporting.export.statement_timeout_seconds'),
            ),
            $app->make(InstallationSiteProvider::class),
            $app->make(Clock::class),
            $app->make(PersonalDataAccessLog::class),
            $app->make(CompliancePolicyProvider::class),
            $app->make(ComplianceProfileReference::class),
        );
    }

    /**
     * La zona de limitacion de la **descarga sin sesion** (ADR-041, RS-02).
     *
     * ## Por IP y no por cuenta, porque aqui no hay cuenta
     *
     * Es la unica zona del producto que no puede contar por actor: la ruta va
     * fuera del grupo autenticado a proposito —un enlace que se abre con un clic
     * no lleva cabecera `Authorization`—. El eje que queda es el origen, y esto
     * es lo que impide que alguien que conozca un `uuid` pruebe tokens a la
     * velocidad de la red. El resto de la defensa es el tamaño del secreto: ~74
     * bits aleatorios del `uuid` v7 —48 de sus 122 son marca de tiempo— mas 256
     * bits de token, y un solo uso.
     *
     * ## Treinta y no tres
     *
     * La misma cifra que la zona de la exportacion integra, y por el mismo
     * motivo de campo: en un hotel, recepcion, direccion y el despacho de RRHH
     * salen a internet por **la misma IP publica**. Con el techo de la zona de
     * diagnostico —tres— tres personas descargando informes a la vez se
     * cortarian entre si, y ese `429` no protege nada: lo que de verdad limita
     * aqui es que cada enlace sirve una sola vez.
     *
     * Es configuracion y no una constante (regla dura 13).
     */
    private function limitReportDownloads(): void
    {
        RateLimiter::for('report-download', static fn (Request $request): Limit => Limit::perMinute(
            max(1, Config::integer('reporting.export.download_rate_limit_per_minute', 30)),
        )->by('report-download-ip:'.(string) $request->ip()));
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
