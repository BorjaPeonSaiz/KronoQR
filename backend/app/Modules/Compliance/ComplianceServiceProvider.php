<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Attendance\Domain\Event\AttendanceAnomalyDetected;
use App\Modules\Attendance\Domain\Event\AttendanceReviewCompleted;
use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use App\Modules\Compliance\Application\Port\AuditMetrics;
use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\Port\IncidentAssignment;
use App\Modules\Compliance\Application\Port\IncidentBoard;
use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\Port\IncidentMetrics;
use App\Modules\Compliance\Application\Port\IncidentNotices;
use App\Modules\Compliance\Application\Port\IncidentNotifier;
use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Compliance\Application\Port\LegalExportAudit;
use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\Port\LegalExportWriter;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Http\Policy\IncidentPolicy;
use App\Modules\Compliance\Http\Policy\LegalExportPolicy;
use App\Modules\Compliance\Infrastructure\Adapter\AuditedAuthenticationJournal;
use App\Modules\Compliance\Infrastructure\Adapter\AuditedAuthorizationJournal;
use App\Modules\Compliance\Infrastructure\Adapter\AuditedLegalExportGeneration;
use App\Modules\Compliance\Infrastructure\Adapter\AuditedPersonalDataAccessLog;
use App\Modules\Compliance\Infrastructure\Adapter\GroupedAuthorizationJournal;
use App\Modules\Compliance\Infrastructure\Adapter\GroupedPersonalDataAccessLog;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Compliance\Infrastructure\Console\EnsureAuditPartitionsCommand;
use App\Modules\Compliance\Infrastructure\Console\IncidentMetricsCommand;
use App\Modules\Compliance\Infrastructure\Console\LegalExportCommand;
use App\Modules\Compliance\Infrastructure\Console\PurgeOrphanedLegalExportTempFilesCommand;
use App\Modules\Compliance\Infrastructure\Console\VerifyAuditChainCommand;
use App\Modules\Compliance\Infrastructure\Export\CsvLegalExportWriter;
use App\Modules\Compliance\Infrastructure\Listener\NotifyIncidentAssignees;
use App\Modules\Compliance\Infrastructure\Listener\OpenIncidentOnAnomalyDetected;
use App\Modules\Compliance\Infrastructure\Listener\RecordCredentialLifecycle;
use App\Modules\Compliance\Infrastructure\Listener\RecordEmployeePinLifecycle;
use App\Modules\Compliance\Infrastructure\Listener\RecordManagementAccountLifecycle;
use App\Modules\Compliance\Infrastructure\Listener\RecordShiftEntryAudit;
use App\Modules\Compliance\Infrastructure\Metrics\RedisIncidentResolutionMetrics;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileAuditMetrics;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileIncidentMetrics;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileLegalExportMetrics;
use App\Modules\Compliance\Infrastructure\Notification\MailIncidentNotifier;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditChainReader;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditLogPartitions;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseIncidentAssignment;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseIncidentBoard;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseIncidentLedger;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseIncidentNotices;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseLegalExportSource;
use App\Modules\Identity\Domain\Event\CredentialDelivered;
use App\Modules\Identity\Domain\Event\CredentialIssued;
use App\Modules\Identity\Domain\Event\CredentialPrinted;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Identity\Domain\Event\DeviceTokenRevoked;
use App\Modules\Identity\Domain\Event\ManagementRoleAssigned;
use App\Modules\Identity\Domain\Event\TwoFactorEnabled;
use App\Modules\Identity\Domain\Event\TwoFactorReset;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\AuthorizationJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Workforce\Domain\Event\EmployeePinDelivered;
use App\Modules\Workforce\Domain\Event\EmployeePinIssued;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Compliance — auditoria, incidencias, retencion y exportacion legal
 * (doc 02 §1.6). Depende de Shared y reacciona a eventos de Attendance.
 *
 * Nunca llama a Attendance: se suscribe a sus eventos de dominio. Los listeners
 * que traducen esos eventos a entradas de `audit_log` llegan con las tareas que
 * los producen —1.4 el fichaje, 1.5 el dispositivo, 1.13 la credencial—; lo que
 * ya existe aqui es la puerta por la que entran: el puerto `AuditTrail` y el
 * caso de uso `RecordAuditEntry`.
 *
 * **Los dos enlaces con conexion distinta no son un descuido.** El escritor y el
 * lector de la cadena corren con la conexion por defecto, que es la del rol de
 * la aplicacion: sin DDL y sin `UPDATE` ni `DELETE` sobre `audit_log` (regla
 * dura 6). El gestor de particiones corre con la conexion de **migracion**,
 * porque crear una particion es DDL. Es la unica pieza del modulo que la usa, y
 * solo la ejecuta un comando programado.
 */
final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            AuditTrail::class,
            static fn (): DatabaseAuditTrail => new DatabaseAuditTrail(DB::connection()),
        );

        $this->app->singleton(
            AuditChainReader::class,
            static fn (): DatabaseAuditChainReader => new DatabaseAuditChainReader(DB::connection()),
        );

        $this->app->singleton(
            AuditLogPartitions::class,
            static fn (): DatabaseAuditLogPartitions => new DatabaseAuditLogPartitions(
                DB::connection(Config::string('database.migrations.connection', 'pgsql_migrator')),
            ),
        );

        $this->app->singleton(AuditMetrics::class, TextfileAuditMetrics::class);

        /*
         * RS-05: todo acceso a datos personales de terceros deja traza.
         *
         * Es la tercera via del §1.6 —ADR-025— y no un evento de dominio: aqui no
         * ha pasado nada en el dominio de nadie, ha pasado que **alguien leyo**.
         * El puerto vive en `Shared/Application/Port` para que quien divulga
         * —hoy el padron del quiosco (tarea 1.7), mañana el detalle de jornada
         * (1.16) y la exportacion legal (1.17)— no tenga que importar este
         * modulo, que es la arista que el §1.6 no concede.
         *
         * Deliberadamente estrecho: no acepta accion ni actor. La accion es
         * siempre `personal_data.accessed` y el actor sale de la peticion en
         * curso. Si aceptara cualquier accion, el catalogo cerrado de
         * `AuditAction` dejaria de estar cerrado.
         */
        /*
         * ENVUELTO EN LA AGRUPACION POR VENTANA, y **solo para los conjuntos que
         * se sondean** (tarea 2.4, ADR-037).
         *
         * Hoy hay uno: la presencia en vivo, que el panel pide cada 15 s cuando
         * el WebSocket no llega (RNF-D-03). Un asiento por sondeo no responde
         * mejor a RL-15 —dice lo mismo veinte mil veces al dia— y mete esas
         * escrituras bajo el candado global de ADR-010, el mismo del fichaje.
         * Todo lo demas —el directorio de plantilla, el padron del quiosco, la
         * exportacion legal— sigue dejando un asiento por lectura, porque ahi
         * cada lectura es un acto distinto de una persona y no el latido de una
         * pantalla abierta.
         *
         * El decorado sigue siendo `AuditedPersonalDataAccessLog`: la cadena de
         * hash no se toca, y el hecho tampoco se pierde —el primer asiento de
         * cada ventana se escribe siempre—.
         */
        $this->app->bind(
            PersonalDataAccessLog::class,
            static fn (Application $app): PersonalDataAccessLog => new GroupedPersonalDataAccessLog(
                disclosures: $app->make(AuditedPersonalDataAccessLog::class),
                context: $app->make(CurrentAuditContext::class),
                cache: $app->make(CacheRepository::class),
                // Se leen aqui y no dentro del decorador porque el enlace se
                // resuelve por peticion: `config:cache` y una prueba que cambie
                // el valor surten efecto igual.
                groupedDatasets: array_values(array_filter(
                    Config::array('compliance.disclosure_grouping.datasets', []),
                    static fn (mixed $dataset): bool => \is_string($dataset),
                )),
                windowSeconds: Config::integer('compliance.disclosure_grouping.window_seconds', 900),
            ),
        );

        /*
         * OWASP A09: la autenticacion deja rastro consultable.
         *
         * Misma via que el puerto de arriba y por el mismo motivo: quien
         * autentica —`Identity` en el panel y el portal, `Workforce` en el
         * verificador del PIN, `Attendance` en el fichaje de respaldo— no puede
         * importar este modulo. El reparto entre `audit_log` y el log tecnico lo
         * decide el adaptador, no quien llama: si cada caso de uso pudiera
         * elegir, el fallo acabaria en la cadena de hash el dia que a alguien le
         * pareciera mas completo, y con el el candado global de ADR-010 dentro
         * del camino de fichaje.
         */
        $this->app->bind(AuthenticationJournal::class, AuditedAuthenticationJournal::class);

        /*
         * RF-ID-03 y RS-05: el intento de salirse del alcance por departamento.
         *
         * Tercer puerto por la misma via y con el mismo criterio que los dos de
         * arriba. Es distinto de `PersonalDataAccessLog` a proposito: aquel
         * describe una divulgacion —alguien se llevo N registros— y este
         * describe un intento que **no** se sirvio. Mezclarlos obligaria a leer
         * `record_count: 0` como «denegado», una convencion que nadie recuerda
         * seis meses despues.
         */
        /*
         * ENVUELTO EN LA AGRUPACION POR VENTANA, y esta es la unica escritura de
         * `audit_log` que la lleva.
         *
         * Todas las demas las provoca un acto de gestion; esta la provoca **quien
         * esta siendo rechazado**, asi que un bucle de peticiones denegadas es un
         * bucle de escrituras bajo el candado global de ADR-010 —el mismo del
         * camino de fichaje—. La palanca es la que nombra ADR-037 para este
         * problema: agrupar por frecuencia, entera detras del puerto, sin que
         * `Workforce`, `Reporting` ni `Attendance` se enteren y **sin quitar el
         * asiento** que exige el escenario «Aislamiento por departamento».
         *
         * El decorado sigue siendo `AuditedAuthorizationJournal`: la cadena de
         * hash no se toca.
         */
        $this->app->bind(
            AuthorizationJournal::class,
            static fn (Application $app): AuthorizationJournal => new GroupedAuthorizationJournal(
                journal: $app->make(AuditedAuthorizationJournal::class),
                context: $app->make(CurrentAuditContext::class),
                cache: $app->make(CacheRepository::class),
                // Se lee aqui y no dentro del decorador porque el enlace se
                // resuelve por peticion: `config:cache` y una prueba que cambie el
                // valor surten efecto igual.
                windowSeconds: Config::integer('compliance.authorization_denial_window_seconds', 60),
            ),
        );

        $this->registerLegalExport();
        $this->registerIncidents();
    }

    public function boot(): void
    {
        $this->recordShiftEntryLifecycle();
        $this->recordCredentialAndDeviceLifecycle();
        $this->recordEmployeePinLifecycle();
        $this->recordManagementAccountLifecycle();
        $this->openAndNotifyIncidents();

        /*
         * La policy de la exportacion legal (RF-IN-05, regla dura 18).
         *
         * Se registra contra {@see LegalExport} —el resultado del caso de uso— y
         * no contra un modelo Eloquent, porque **el recurso que se autoriza no
         * es una fila de ninguna tabla**: es el documento que se entrega a la
         * Inspeccion. Laravel resuelve una policy por nombre de clase y le da
         * igual cual sea; lo que hace falta es que ese nombre signifique algo
         * para quien lea `Gate::allows('generate', LegalExport::class)` en el
         * `FormRequest`.
         */
        Gate::policy(LegalExport::class, LegalExportPolicy::class);

        /*
         * La policy de la bandeja de incidencias (RF-PA-05, regla dura 18).
         *
         * Se registra contra {@see Incident} —el modelo de dominio— y no contra
         * un modelo Eloquent, por lo mismo que la de arriba: asi la autorizacion
         * se decide **antes** de tocar la base de datos. Declarada sobre una fila
         * habria que cargarla para poder preguntar si se puede leer, que es
         * exactamente lo que un `403` tiene que poder evitar.
         */
        Gate::policy(Incident::class, IncidentPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyAuditChainCommand::class,
                EnsureAuditPartitionsCommand::class,
                /*
                 * `compliance:legal-export` (doc 02 Anexo C, plan 1.17 paso 7).
                 *
                 * NO va en `routes/console.php`: ahi vive CUANDO se ejecuta cada
                 * comando programado, y este no se programa. Un requerimiento de
                 * Inspeccion llega cuando llega, y una exportacion legal que se
                 * generara sola todas las noches seria una copia nominal de la
                 * plantilla escribiendose en disco sin que nadie la pidiera.
                 */
                LegalExportCommand::class,
                /*
                 * `compliance:purge-legal-export-temp` (MEDIO-3 del cierre de
                 * Fase 1). Este SI se programa -en routes/console.php-: a
                 * diferencia de la exportacion en si, que solo se genera
                 * cuando alguien la pide, el huerfano de una descarga
                 * abortada puede aparecer cualquier noche sin que nadie lo
                 * pida ni lo note.
                 */
                PurgeOrphanedLegalExportTempFilesCommand::class,
                /*
                 * `compliance:incident-metrics` (doc 02 §8.2, tarea 2.6). SI se
                 * programa, y aparte de la deteccion: el gauge de incidencias
                 * abiertas tiene que BAJAR cuando alguien resuelve una desde la
                 * bandeja, no solo subir cuando la revision nocturna encuentra
                 * algo (regla dura 7 aplicada a la instrumentacion).
                 */
                IncidentMetricsCommand::class,
            ]);
        }
    }

    /**
     * El mapa evento -> incidencia de la revision diaria (RF-PR-01, tarea 2.6).
     *
     * Misma via que la auditoria del fichaje: `Attendance` no puede importar este
     * modulo, asi que emite y `Compliance` reacciona. Que la lista viva aqui es lo
     * que permite ver de un vistazo todo lo que este modulo escucha.
     *
     * **Sincronos y sin transaccion envolvente.** Cada hallazgo abre su incidencia
     * en la suya —la del caso de uso, con su asiento dentro—, de modo que uno que
     * falle no se lleva por delante a los demas de la misma pasada. El aviso al
     * responsable, que es lo unico con efecto fuera de la base de datos, va
     * encolado dentro de su adaptador.
     */
    private function openAndNotifyIncidents(): void
    {
        Event::listen(AttendanceAnomalyDetected::class, [OpenIncidentOnAnomalyDetected::class, 'handle']);
        Event::listen(AttendanceReviewCompleted::class, [NotifyIncidentAssignees::class, 'handle']);
    }

    /**
     * Las incidencias del registro horario (RF-PR-01, tarea 2.6).
     *
     * Siete puertos, y ninguno lo conoce `Attendance`: el nucleo emite hallazgos
     * y este modulo decide severidad, responsable, aviso y metrica (doc 01 §5.1,
     * doc 02 §1.6). Los dos ultimos son de la bandeja de la tarea 2.5: el lado de
     * **lectura**, separado del libro que escribe, y el histograma de resolucion,
     * separado del gauge de abiertas porque uno se observa y el otro se
     * recalcula.
     *
     * **El escritor no es un `singleton`**, igual que el resto de adaptadores que
     * escriben: no tiene estado que compartir y una instancia viva entre
     * peticiones —Octane, un worker de colas— es una fuente de sorpresas sin
     * ninguna ventaja. La metrica si, porque no toca la base de datos y solo
     * escribe un fichero.
     */
    private function registerIncidents(): void
    {
        $this->app->bind(
            IncidentLedger::class,
            static fn (Application $app): DatabaseIncidentLedger => new DatabaseIncidentLedger(
                DB::connection(),
                $app->make(Clock::class),
            ),
        );

        $this->app->bind(
            IncidentAssignment::class,
            static fn (): DatabaseIncidentAssignment => new DatabaseIncidentAssignment(DB::connection()),
        );

        $this->app->bind(
            IncidentNotices::class,
            static fn (): DatabaseIncidentNotices => new DatabaseIncidentNotices(DB::connection()),
        );

        $this->app->bind(IncidentNotifier::class, MailIncidentNotifier::class);

        /*
         * El lado de LECTURA de la bandeja (tarea 2.5).
         *
         * Puerto propio y no un metodo mas de `IncidentLedger`: aquel escribe y
         * habla en el agregado, este solo lee y lo que devuelve lleva ademas
         * nombres de personas y de cuentas. Juntarlos habria dado una consulta de
         * bandeja capaz de escribir.
         */
        $this->app->bind(
            IncidentBoard::class,
            static fn (): DatabaseIncidentBoard => new DatabaseIncidentBoard(DB::connection()),
        );

        $this->app->singleton(IncidentMetrics::class, TextfileIncidentMetrics::class);

        /*
         * `incident_resolution_seconds{type}` sobre Redis y no *textfile* (tarea
         * 2.5). Es un histograma que se observa dentro de una peticion, no un
         * gauge que una tarea programada recalcula: ver el docblock del
         * adaptador. `singleton` por lo mismo que las demas metricas: no toca la
         * base de datos y no arrastra estado de la peticion.
         */
        $this->app->singleton(IncidentResolutionMetrics::class, RedisIncidentResolutionMetrics::class);
    }

    /**
     * Las cuatro piezas de la exportacion legal (RF-IN-05, RL-03, RL-06).
     *
     * **El origen usa la conexion por defecto**, la del rol de la aplicacion:
     * solo lee, y el cursor de servidor que declara vive dentro de la
     * transaccion que abre el caso de uso.
     *
     * **La auditoria no es un `singleton`**, igual que el resto de adaptadores
     * que leen la peticion en curso: quien exporta sale del guard, y una
     * instancia compartida entre peticiones —Octane, un worker de colas— podria
     * arrastrar el actor de la anterior. Firmar un asiento con el nombre
     * equivocado es peor que no firmarlo.
     */
    private function registerLegalExport(): void
    {
        $this->app->bind(
            LegalExportSource::class,
            static fn (): DatabaseLegalExportSource => new DatabaseLegalExportSource(DB::connection()),
        );

        $this->app->bind(LegalExportWriter::class, CsvLegalExportWriter::class);

        $this->app->bind(LegalExportAudit::class, AuditedLegalExportGeneration::class);

        $this->app->singleton(LegalExportMetrics::class, TextfileLegalExportMetrics::class);
    }

    /**
     * El mapa evento -> asiento de `audit_log` del **fichaje** (RL-01, regla
     * dura 6, tarea 1.4).
     *
     * Es la unica via por la que `Attendance` deja traza: no puede importar este
     * modulo —el §1.6 no concede esa arista y Deptrac la verifica—, asi que
     * emite y `Compliance` reacciona. Que la lista viva aqui y no en
     * `AttendanceServiceProvider` es lo que hace que el nucleo no sepa siquiera
     * que la auditoria existe.
     *
     * **Sincronos y no encolados, a proposito.** `RegisterScanHandler` publica
     * sus eventos dentro de su transaccion, de modo que este asiento entra en
     * ella: si falla, el fichaje **no se confirma** (ADR-027, contrato de
     * `AuditTrail`). Un listener encolado auditaria despues, y un fichaje ya
     * confirmado sin traza es indefendible ante una inspeccion.
     */
    private function recordShiftEntryLifecycle(): void
    {
        Event::listen(EmployeeClockedIn::class, [RecordShiftEntryAudit::class, 'clockedIn']);
        Event::listen(EmployeeClockedOut::class, [RecordShiftEntryAudit::class, 'clockedOut']);

        /*
         * Y la correccion manual (tarea 1.15, RF-PA-04, RN-13, RL-04).
         *
         * **Un solo evento para las cuatro acciones** —alta, cambio de hora,
         * cierre de un turno olvidado y anulacion— porque legalmente son el mismo
         * hecho: el registro que se defiende ante Inspeccion ya no es el que
         * produjo el quiosco, y hay una persona que responde de ello. El listener
         * distingue por `action` y escribe la accion de `audit_log` que le
         * corresponde; cuatro eventos distintos habrian obligado a cuatro
         * suscripciones que escriben la misma fila.
         *
         * **Sincrono y sin `afterCommit`, como los dos de arriba.** Los tres
         * casos de uso de la correccion publican sus eventos **dentro** de su
         * transaccion, asi que este asiento entra en ella: si falla, la
         * correccion no se confirma (ADR-027, regla dura 6). Una correccion sin
         * traza es peor que ninguna correccion, porque cambia las horas de
         * alguien sin decir quien lo hizo. Este listener no tiene ningun efecto
         * fuera de la base de datos —no envia nada, no escribe ficheros—, que es
         * la condicion para poder correr dentro de la transaccion.
         */
        Event::listen(ShiftCorrected::class, [RecordShiftEntryAudit::class, 'corrected']);
    }

    /**
     * El mapa evento -> asiento de `audit_log` de la tarea 1.5 (regla dura 6).
     *
     * **Se escribe aqui y no en `Identity`.** El modulo que produce el hecho no
     * tiene que saber quien lo apunta; el que audita si tiene que saber que
     * hechos audita, y esa lista es esta. Que este a la vista, en la raiz de
     * composicion del modulo, es lo que permite revisar de un vistazo si falta
     * alguno.
     *
     * Los listeners son **sincronos**: sin `ShouldQueue` y sin `afterCommit`.
     * Si el asiento falla, la accion auditada no se confirma (ADR-027).
     */
    private function recordCredentialAndDeviceLifecycle(): void
    {
        Event::listen(CredentialIssued::class, [RecordCredentialLifecycle::class, 'handleCredentialIssued']);
        // Imprimir y entregar son de la tarea 1.10. El primero es el momento en
        // que una persona pasa a poder fichar (ADR-034) y el segundo distingue
        // «se perdio antes de darsela» de «la perdio el empleado» (§5.5): los dos
        // son hechos con relevancia legal y los dos dejan asiento.
        Event::listen(CredentialPrinted::class, [RecordCredentialLifecycle::class, 'handleCredentialPrinted']);
        Event::listen(CredentialDelivered::class, [RecordCredentialLifecycle::class, 'handleCredentialDelivered']);
        Event::listen(CredentialRevoked::class, [RecordCredentialLifecycle::class, 'handleCredentialRevoked']);
        Event::listen(DeviceTokenIssued::class, [RecordCredentialLifecycle::class, 'handleDeviceTokenIssued']);
        Event::listen(DeviceTokenRevoked::class, [RecordCredentialLifecycle::class, 'handleDeviceTokenRevoked']);
    }

    /**
     * El mapa evento -> asiento de `audit_log` del PIN (tarea 1.13, RF-ID-09).
     *
     * **Las tres acciones que RF-ID-09 exige auditar por escrito** —emision,
     * restablecimiento y entrega— entran por dos eventos: emitir y restablecer
     * son el mismo hecho con una bandera que los distingue, igual que emitir y
     * reemitir una credencial.
     *
     * Sincronos, sin `ShouldQueue` y sin `afterCommit`: si el asiento falla, el
     * PIN no se emite y la entrega no se registra (ADR-027). Un PIN emitido sin
     * traza da acceso al registro horario de una persona sin que conste quien lo
     * dio.
     */
    private function recordEmployeePinLifecycle(): void
    {
        Event::listen(EmployeePinIssued::class, [RecordEmployeePinLifecycle::class, 'handleIssued']);
        Event::listen(EmployeePinDelivered::class, [RecordEmployeePinLifecycle::class, 'handleDelivered']);
    }

    /**
     * El mapa evento -> asiento de las **cuentas de gestion** (tarea 2.1, RS-05,
     * RS-06).
     *
     * Dos familias del bloque D en un solo listener, y por eso no vive con las
     * credenciales: el segundo factor es ciclo de vida de una credencial de
     * acceso y el rol es «cambia roles, permisos o configuracion».
     *
     * Sincronos, sin `ShouldQueue` y sin `afterCommit`: si el asiento falla, ni
     * se activa el segundo factor ni se asigna el rol (ADR-027). Un rol concedido
     * sin traza deja sin respuesta la pregunta que se hace despues de un
     * incidente: quien le dio acceso a esta persona.
     */
    private function recordManagementAccountLifecycle(): void
    {
        Event::listen(TwoFactorEnabled::class, [RecordManagementAccountLifecycle::class, 'handleTwoFactorEnabled']);
        Event::listen(TwoFactorReset::class, [RecordManagementAccountLifecycle::class, 'handleTwoFactorReset']);
        Event::listen(ManagementRoleAssigned::class, [RecordManagementAccountLifecycle::class, 'handleRoleAssigned']);
    }
}
