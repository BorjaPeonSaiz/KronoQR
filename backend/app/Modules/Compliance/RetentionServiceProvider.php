<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Compliance\Application\Port\AuditPartitionArchive;
use App\Modules\Compliance\Application\Port\AuditPartitionInventory;
use App\Modules\Compliance\Application\Port\ErrorHistoryArchive;
use App\Modules\Compliance\Application\Port\RetentionMetrics;
use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Compliance\Application\Port\RetentionReportStore;
use App\Modules\Compliance\Application\Port\TechnicalLogArchive;
use App\Modules\Compliance\Application\Port\WorkRecordArchive;
use App\Modules\Compliance\Infrastructure\Adapter\ConfiguredRetentionPolicyProvider;
use App\Modules\Compliance\Infrastructure\Console\ApplyRetentionCommand;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileRetentionMetrics;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditPartitionArchive;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseErrorHistoryArchive;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use App\Modules\Compliance\Infrastructure\Retention\FileRetentionReportStore;
use App\Modules\Compliance\Infrastructure\Retention\FilesystemTechnicalLogArchive;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * La retencion del modulo `Compliance` (RL-02, RL-11, RF-PR-03, tarea 2.10).
 *
 * ## Por que va en un proveedor propio
 *
 * Por **dos conexiones distintas y una decision de permisos**, que es lo mismo
 * que ya justifica los dos enlaces con conexion distinta de
 * {@see ComplianceServiceProvider}: aqui la misma clase se registra dos veces,
 * como inventario con el rol de la **aplicacion** —que solo cuenta— y como
 * archivo con el de **mantenimiento** —el unico que puede soltar una particion,
 * ADR-033—. Tenerlo junto, con su porque al lado, es lo que evita que alguien
 * «simplifique» el enlace de arriba usando la conexion del de abajo y deje el
 * `--dry-run` exigiendo la credencial capaz de destruir.
 *
 * Si en una revision posterior se prefiere un unico proveedor por modulo, mover
 * estos enlaces a `ComplianceServiceProvider::registerRetention()` es mecanico:
 * el `register()` y el `boot()` de aqui son el cuerpo del metodo.
 */
final class RetentionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Los plazos, con sus dos fuentes juntas: los anos del perfil de
         * cumplimiento del centro y los dias de la configuracion de la
         * instalacion (regla dura 14).
         *
         * `scoped` y no `singleton` por lo mismo que
         * `DbCompliancePolicyProvider`: memoriza dentro de una ejecucion y no
         * entre peticiones, para que un cambio del perfil surta efecto en la
         * siguiente sin reiniciar nada.
         */
        $this->app->scoped(
            RetentionPolicyProvider::class,
            static fn (Application $app): ConfiguredRetentionPolicyProvider => new ConfiguredRetentionPolicyProvider(
                $app->make(CompliancePolicyProvider::class),
                $app->make(InstallationSiteProvider::class),
            ),
        );

        /*
         * El registro de jornada se purga con el rol de la APLICACION, que es el
         * que tiene `DELETE` sobre esas tablas y —esto es lo importante— el unico
         * que puede escribir el asiento de `audit_log` en la misma transaccion
         * (regla dura 6). Con el rol de mantenimiento, borrado y constancia
         * caerian en dos sesiones distintas.
         */
        $this->app->bind(
            WorkRecordArchive::class,
            static fn (): DatabaseWorkRecordArchive => new DatabaseWorkRecordArchive(DB::connection()),
        );

        /*
         * LA MISMA CLASE, DOS ENLACES Y DOS ROLES.
         *
         * El inventario —contar filas y ver rangos para el informe— con el rol de
         * la aplicacion: es lo que usa el `--dry-run`, que tiene que poder
         * lanzarse siempre, tambien en una instalacion que todavia no custodia la
         * credencial de mantenimiento. La simulacion no puede necesitar mas
         * permisos que los que necesita para simular.
         */
        $this->app->bind(
            AuditPartitionInventory::class,
            static fn (): DatabaseAuditPartitionArchive => new DatabaseAuditPartitionArchive(DB::connection()),
        );

        /*
         * Y el archivo —verificar, sellar y soltar— con el rol de MANTENIMIENTO
         * (ADR-027, ADR-033). La conexion se resuelve de forma perezosa: crearla
         * no abre socket, asi que declarar este enlace no obliga a nadie a tener
         * la credencial hasta que se ejecuta una purga de verdad.
         */
        $this->app->bind(
            AuditPartitionArchive::class,
            static fn (): DatabaseAuditPartitionArchive => new DatabaseAuditPartitionArchive(
                DB::connection(Config::string('compliance.retention.maintenance_connection', 'pgsql_maintenance')),
            ),
        );

        $this->app->bind(
            ErrorHistoryArchive::class,
            static fn (): DatabaseErrorHistoryArchive => new DatabaseErrorHistoryArchive(DB::connection()),
        );

        $this->app->bind(TechnicalLogArchive::class, FilesystemTechnicalLogArchive::class);

        $this->app->bind(RetentionReportStore::class, FileRetentionReportStore::class);

        // `singleton` como las demas metricas: no toca la base de datos y no
        // arrastra estado de la peticion.
        $this->app->singleton(RetentionMetrics::class, TextfileRetentionMetrics::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            /*
             * `compliance:apply-retention`. SI se programa, pero **solo en
             * simulacion** (`routes/console.php`): la ejecucion destructiva nunca
             * es automatica (RF-PR-03, regla dura 5).
             */
            $this->commands([ApplyRetentionCommand::class]);
        }
    }
}
