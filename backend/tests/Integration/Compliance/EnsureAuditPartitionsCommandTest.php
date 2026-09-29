<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditLogPartitions;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FrozenTime;

/*
 * `compliance:ensure-audit-partitions` sin la credencial del migrador (ADR-042,
 * RS-07, hallazgo AUD-1 de la 2.1.0).
 *
 * LO QUE SE DEMUESTRA. Que el comando programado **no nombra** la conexion del
 * migrador y que funciona **con los privilegios del rol de la aplicacion**: la
 * particion la crea la funcion `SECURITY DEFINER`, no quien llama. Y que, si la
 * funcion falta, falla con su mensaje, deja constancia en el log sin datos de
 * nadie y publica la metrica a 0 para que suene la alerta.
 *
 * EL PATRON. Tras `migrate:fresh` ya existen las particiones del año en curso y
 * del siguiente, asi que hay que soltar la del siguiente para que el comando
 * tenga algo que crear, y eso solo puede hacerlo el propietario. Se usa un
 * CLON de la configuracion de la conexion del migrador con otro nombre
 * (`pgsql_owner_as_app`) y se hace conexion por defecto: dentro de una
 * transaccion suya se prepara como propietario, se cambia con `SET LOCAL ROLE`
 * al rol de la aplicacion y se ejecuta el comando, que resuelve el adaptador
 * sobre `DB::connection()`. La conexion `pgsql_migrator` de verdad se
 * inutiliza antes (host de documentacion, contraseña falsa): si el comando la
 * abriera, la prueba fallaria. Al final todo se deshace y se restaura la
 * conexion por defecto.
 *
 * LOS AÑOS SALEN DEL MOTOR, porque la ventana de la funcion usa el `now()` de
 * PostgreSQL; el reloj del caso de uso se congela en noviembre de ESE año.
 */

uses(RefreshDatabase::class);

const ENSURE_AUDIT_PARTITIONS_CONNECTION = 'pgsql_owner_as_app';

/**
 * @param  Closure(Connection, int): void  $preparar  Como propietario; recibe el año siguiente.
 * @param  Closure(Connection, int): void  $probar  Con el rol de la aplicacion; recibe el año siguiente.
 */
function particionesComandoConConexionDeLaAplicacion(Closure $preparar, Closure $probar): void
{
    $previousDefault = DB::getDefaultConnection();

    /** @var array<string, mixed> $migrator */
    $migrator = Config::array('database.connections.pgsql_migrator');
    Config::set('database.connections.'.ENSURE_AUDIT_PARTITIONS_CONNECTION, $migrator);

    $connection = DB::connection(ENSURE_AUDIT_PARTITIONS_CONNECTION);

    /** @var object{y: int}|null $row */
    $row = $connection->selectOne("SELECT extract(year FROM now() AT TIME ZONE 'UTC')::int AS y");
    $next = (int) $row?->y + 1;

    $connection->beginTransaction();

    try {
        $connection->statement("SET LOCAL lock_timeout = '5s'");

        $preparar($connection, $next);

        $connection->statement('SET LOCAL ROLE "'.AuditLogSchema::applicationRole().'"');

        // La credencial del migrador deja de servir: cualquier intento de
        // abrir esa conexion desde aqui en adelante falla.
        Config::set('database.connections.pgsql_migrator.host', '203.0.113.1');
        Config::set('database.connections.pgsql_migrator.password', 'no-es-la-contraseña');
        // Que un intento indebido falle en segundos y no tras el minuto y medio
        // de espera TCP por defecto.
        Config::set('database.connections.pgsql_migrator.connect_timeout', 2);
        DB::purge('pgsql_migrator');

        DB::setDefaultConnection(ENSURE_AUDIT_PARTITIONS_CONNECTION);
        app()->forgetInstance(AuditLogPartitions::class);

        FrozenTime::at(($next - 1).'-11-15 02:45:00');

        $probar($connection, $next);
    } finally {
        $connection->rollBack();
        DB::setDefaultConnection($previousDefault);
        app()->forgetInstance(AuditLogPartitions::class);
        DB::purge(ENSURE_AUDIT_PARTITIONS_CONNECTION);
    }
}

function particionesComandoMetricas(): string
{
    $path = sys_get_temp_dir().'/kronoqr-audit-partitions-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $path);
    Config::set('observability.metrics.enabled', true);

    return $path;
}

it('crea la particion con la conexion de la aplicacion aunque la del migrador no sirva', function (): void {
    $metrics = particionesComandoMetricas();

    /** @var list<string> $abiertas */
    $abiertas = [];
    Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use (&$abiertas): void {
        $abiertas[] = $event->connectionName;
    });

    particionesComandoConConexionDeLaAplicacion(
        static function (Connection $owner, int $next): void {
            $partition = AuditLogSchema::partitionName($next);
            $owner->statement('ALTER TABLE public.audit_log DETACH PARTITION public.'.$partition);
            $owner->statement('DROP TABLE public.'.$partition);
        },
        static function (Connection $app, int $next) use ($metrics): void {
            expect((new DatabaseAuditLogPartitions($app))->years())->not->toContain($next);

            $exitCode = Artisan::call('compliance:ensure-audit-partitions');
            // `output()` vacia el buffer al leerlo: se lee una sola vez.
            $output = Artisan::output();

            expect($exitCode)->toBe(0, $output)
                ->and($output)->toContain('audit_log_'.$next)
                ->and((new DatabaseAuditLogPartitions($app))->years())->toContain($next)
                ->and((string) file_get_contents($metrics.'/kronoqr_audit_partitions.prom'))
                ->toContain('audit_log_partition_ready{horizon="next"} 1');
        },
    );

    // La prueba de ejecucion de ADR-042: en toda la pasada nadie abrio la
    // conexion del migrador.
    expect($abiertas)->not->toContain('pgsql_migrator');
})->group('RS-07', 'RS-08');

it('falla con mensaje propio y metrica a cero si falta la funcion', function (): void {
    $metrics = particionesComandoMetricas();

    particionesComandoConConexionDeLaAplicacion(
        static function (Connection $owner, int $next): void {
            $partition = AuditLogSchema::partitionName($next);
            $owner->statement('ALTER TABLE public.audit_log DETACH PARTITION public.'.$partition);
            $owner->statement('DROP TABLE public.'.$partition);
            $owner->statement(AuditLogSchema::createFunctionRemovalStatement());
        },
        static function (Connection $app, int $next) use ($metrics): void {
            // `Event::listen` y no `Log::spy()`: el doble del facade deja el
            // contenedor devolviendo `null` por `LoggerInterface`. Se escucha
            // al logger de verdad.
            /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> $logged */
            $logged = [];
            Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
                $logged[] = ['level' => $message->level, 'message' => $message->message, 'context' => $message->context];
            });

            $exitCode = Artisan::call('compliance:ensure-audit-partitions');
            $output = Artisan::output();

            expect($exitCode)->toBe(1, $output)
                ->and($output)->toContain('2026_09_29_100000_audit_log_partition_function')
                ->and($output)->toContain('docs/runbooks/rotura-cadena-auditoria.md')
                ->and((string) file_get_contents($metrics.'/kronoqr_audit_partitions.prom'))
                ->toContain('audit_log_partition_ready{horizon="next"} 0')
                ->toContain('audit_log_partition_ready{horizon="current"} 1');

            // Solo el año: ni nombres ni datos de nadie (regla dura 21).
            expect($logged)->toContain([
                'level' => 'critical',
                'message' => 'audit_log_partition_creation_unavailable',
                'context' => ['year' => $next],
            ]);
        },
    );
})->group('RS-07');

it('resuelve el adaptador de particiones sobre la conexion de la aplicacion', function (): void {
    $partitions = app(AuditLogPartitions::class);

    expect($partitions)->toBeInstanceOf(DatabaseAuditLogPartitions::class);

    $connection = (new ReflectionProperty(DatabaseAuditLogPartitions::class, 'connection'))->getValue($partitions);

    $name = $connection instanceof Connection ? $connection->getName() : null;

    expect($connection)->toBeInstanceOf(Connection::class)
        ->and($name)->toBe(DB::getDefaultConnection())
        ->and($name)->not->toBe('pgsql_migrator');
})->group('RS-07', 'RS-08');
