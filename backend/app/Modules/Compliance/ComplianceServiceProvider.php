<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use App\Modules\Compliance\Application\Port\AuditMetrics;
use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\Port\LegalExportAudit;
use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\Port\LegalExportWriter;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Http\Policy\LegalExportPolicy;
use App\Modules\Compliance\Infrastructure\Adapter\AuditedLegalExportGeneration;
use App\Modules\Compliance\Infrastructure\Adapter\AuditedPersonalDataAccessLog;
use App\Modules\Compliance\Infrastructure\Console\EnsureAuditPartitionsCommand;
use App\Modules\Compliance\Infrastructure\Console\LegalExportCommand;
use App\Modules\Compliance\Infrastructure\Console\PurgeOrphanedLegalExportTempFilesCommand;
use App\Modules\Compliance\Infrastructure\Console\VerifyAuditChainCommand;
use App\Modules\Compliance\Infrastructure\Export\CsvLegalExportWriter;
use App\Modules\Compliance\Infrastructure\Listener\RecordCredentialLifecycle;
use App\Modules\Compliance\Infrastructure\Listener\RecordEmployeePinLifecycle;
use App\Modules\Compliance\Infrastructure\Listener\RecordShiftEntryAudit;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileAuditMetrics;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileLegalExportMetrics;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditChainReader;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditLogPartitions;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseLegalExportSource;
use App\Modules\Identity\Domain\Event\CredentialDelivered;
use App\Modules\Identity\Domain\Event\CredentialIssued;
use App\Modules\Identity\Domain\Event\CredentialPrinted;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Identity\Domain\Event\DeviceTokenRevoked;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Workforce\Domain\Event\EmployeePinDelivered;
use App\Modules\Workforce\Domain\Event\EmployeePinIssued;
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
        $this->app->bind(PersonalDataAccessLog::class, AuditedPersonalDataAccessLog::class);

        $this->registerLegalExport();
    }

    public function boot(): void
    {
        $this->recordShiftEntryLifecycle();
        $this->recordCredentialAndDeviceLifecycle();
        $this->recordEmployeePinLifecycle();

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
            ]);
        }
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
}
