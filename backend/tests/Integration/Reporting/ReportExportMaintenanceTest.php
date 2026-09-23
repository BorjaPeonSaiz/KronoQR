<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\QueuedJobFailureMetrics;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
use App\Modules\Reporting\Domain\Exception\ReportExportAlreadyInProgress;
use App\Modules\Reporting\Infrastructure\Job\GenerateReportExportJob;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\RecordingQueuedJobFailureMetrics;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El esquema de `report_exports` y su mantenimiento (**RF-IN-06**, regla dura 5,
 * ficha 3.9).
 *
 * ## Lo que se prueba contra PostgreSQL y no contra PHP
 *
 * Las invariantes que la ficha pide **declaradas en la migracion**: los catalogos
 * cerrados, que `completed` implique fichero, que la nomina no salga en PDF y —la
 * que ninguna comprobacion en PHP puede cerrar— que **solo haya una exportacion
 * en curso por persona**, garantizada por un indice unico parcial y no por un
 * `SELECT` previo que dos pestañas simultaneas pasarian.
 *
 * Y el `down()`, que la DoD exige verificado: una migracion que no se puede
 * revertir es una actualizacion que no se puede deshacer.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    ReportExports::cleanUpTemporaryPath();
});

function cuentaQuePide(): int
{
    return ManagementUsers::withRole(UserRole::RRHH)->id;
}

it('impide una segunda exportacion en curso de la misma persona, desde la base de datos', function (): void {
    // La red que atrapa la carrera de dos pestañas pulsando a la vez. El endpoint
    // responde `409` antes de llegar aqui; esto es lo que hace que ese `409` sea
    // cierto tambien cuando las dos peticiones entran en el mismo milisegundo.
    $user = cuentaQuePide();

    ReportExports::pendingFor($user);

    expect(static fn () => ReportExports::pendingFor($user))
        ->toThrow(ReportExportAlreadyInProgress::class);
})->group('RF-IN-06');

it('deja a dos personas distintas tener una en curso a la vez', function (): void {
    // Por persona y no por instalacion, al contrario que la exportacion integra:
    // que RRHH este generando el cierre de mes no puede impedirle a otra cuenta
    // pedir el suyo.
    ReportExports::pendingFor(ManagementUsers::withRole(UserRole::RRHH)->id);
    ReportExports::pendingFor(ManagementUsers::withRole(UserRole::ADMIN)->id);

    expect(DB::table('report_exports')->whereIn('status', ['pending', 'running'])->count())->toBe(2);
})->group('RF-IN-06');

it('rechaza desde el esquema un estado, un formato y un motivo de fallo fuera de catalogo', function (): void {
    // Los catalogos se componen DESDE los enumerados del dominio, asi que esta
    // prueba es la que detecta el dia en que uno de los dos se mueva sin el otro.
    $export = ReportExports::pendingFor(cuentaQuePide());

    foreach ([
        ['status' => 'inventado'],
        ['format' => 'docx'],
        ['failure_reason' => 'porque si'],
        ['notification_channel' => 'paloma'],
        ['kind' => 'nomina'],
    ] as $cambio) {
        expect(static fn () => DB::table('report_exports')->where('id', $export->id)->update($cambio))
            ->toThrow(QueryException::class);
    }
})->group('RF-IN-06');

it('rechaza desde el esquema una nomina en PDF', function (): void {
    // Decision 5: un programa de nomina no importa un PDF. La regla vive en el
    // catalogo del dominio, en el `FormRequest`... y aqui, que es donde no se
    // puede saltar por ninguna otra via.
    $export = ReportExports::pendingFor(cuentaQuePide());

    expect(static fn () => DB::table('report_exports')
        ->where('id', $export->id)
        ->update(['kind' => 'payroll', 'format' => 'pdf']))
        ->toThrow(QueryException::class);
})->group('RF-IN-06', 'RF-IN-07');

it('rechaza desde el esquema una exportacion terminada a medias', function (): void {
    // Sin este `CHECK`, un fallo a mitad podria dejar una fila `completed` que la
    // pantalla ofrece descargar y que responde `404`, o una sin `expires_at` que
    // la purga no mirara nunca — un fichero con las horas de la plantilla inmortal
    // en el disco del cliente.
    $export = ReportExports::pendingFor(cuentaQuePide());

    expect(static fn () => DB::table('report_exports')
        ->where('id', $export->id)
        ->update(['status' => 'completed', 'completed_at' => now()]))
        ->toThrow(QueryException::class);
})->group('RF-IN-06');

it('rechaza desde el esquema un enlace a medias', function (): void {
    // Una huella sin fecha seria un enlace que no caduca nunca —justo lo que
    // ADR-041 existe para impedir— y una fecha sin huella, un enlace que no abre
    // nada.
    $export = ReportExports::completedFor(cuentaQuePide());

    expect(static fn () => DB::table('report_exports')
        ->where('id', $export->id)
        ->update(['download_token_hash' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class);
})->group('RF-IN-06');

it('la purga borra el fichero, marca la fila y NO la borra', function (): void {
    /*
     * Regla dura 5. El fichero se va y la fila se queda: «¿salio de aqui un
     * informe con las horas de mi plantilla, cuando y a peticion de quien?» hay
     * que poder contestarlo despues, y una fila borrada no contesta nada.
     */
    $export = ReportExports::completedFor(
        cuentaQuePide(),
        expiresAt: app(Clock::class)->now()->modify('-1 hour'),
    );

    expect(is_file((string) $export->filePath))->toBeTrue();

    $resultado = app(PurgeExpiredReportExports::class)->handle();

    expect($resultado->purged)->toBe(1);

    $purgada = ReportExports::find($export->uuid);

    expect($purgada->status->value)->toBe('purged')
        ->and($purgada->filePath)->toBeNull()
        ->and($purgada->purgedAt)->not->toBeNull()
        // Lo que se conserva: el nombre, la huella y el recuento.
        ->and($purgada->sha256)->toHaveLength(64)
        ->and($purgada->fileName)->toBeString()
        ->and(is_file((string) $export->filePath))->toBeFalse();

    expect(DB::table('report_exports')->count())->toBe(1);
})->group('RF-IN-06');

it('no purga lo que todavia no ha caducado', function (): void {
    ReportExports::completedFor(cuentaQuePide());

    expect(app(PurgeExpiredReportExports::class)->handle()->purged)->toBe(0);
})->group('RF-IN-06');

it('desatasca la exportacion que nadie termino y devuelve el turno a esa persona', function (): void {
    /*
     * El caso real: el servidor se para a mitad de una generacion —el paso 1 de
     * cualquier actualizacion—. Sin este barrido, esa persona se queda con `409`
     * hasta que alguien entre por `psql`.
     */
    Config::set('reporting.export.stale_after_seconds', 1);
    app()->forgetInstance(PurgeExpiredReportExports::class);

    $user = cuentaQuePide();
    $atascada = ReportExports::pendingFor($user);

    DB::table('report_exports')
        ->where('id', $atascada->id)
        ->update(['requested_at' => '2020-01-01 00:00:00+00']);

    $resultado = app(PurgeExpiredReportExports::class)->handle();

    expect($resultado->released)->toBe(1)
        ->and(ReportExports::find($atascada->uuid)->status->value)->toBe('failed')
        ->and(ReportExports::find($atascada->uuid)->failureReason?->value)->toBe('stale');

    // Y el turno esta libre: la siguiente peticion de esa persona ya no choca.
    ReportExports::pendingFor($user);
})->group('RF-IN-06');

it('el comando programado purga y desatasca en la misma pasada', function (): void {
    Config::set('reporting.export.stale_after_seconds', 1);
    app()->forgetInstance(PurgeExpiredReportExports::class);

    $caducada = ReportExports::completedFor(
        ManagementUsers::withRole(UserRole::ADMIN)->id,
        expiresAt: app(Clock::class)->now()->modify('-1 hour'),
    );

    $atascada = ReportExports::pendingFor(cuentaQuePide());

    DB::table('report_exports')
        ->where('id', $atascada->id)
        ->update(['requested_at' => '2020-01-01 00:00:00+00']);

    expect(Artisan::call('reporting:purge-expired-exports'))->toBe(0);

    expect(ReportExports::find($caducada->uuid)->status->value)->toBe('purged')
        ->and(ReportExports::find($atascada->uuid)->status->value)->toBe('failed');
})->group('RF-IN-06');

it('la migracion se puede revertir y volver a aplicar', function (): void {
    /*
     * La DoD lo exige verificado (§10.3): una migracion que no se puede revertir
     * es una actualizacion que no se puede deshacer, y `update.sh` la necesita
     * para volver atras.
     *
     * Revertir **no destruye ninguna evidencia con obligacion de conservacion**:
     * pedir, generar y descargar constan tambien en `audit_log`, que es
     * solo-apendice y se conserva cuatro años (RL-02). Lo que desaparece es el
     * registro operativo de los ficheros, no el rastro legal de que se generaron.
     */
    /*
     * ## Por la conexion de MIGRACION, no por la de la aplicacion
     *
     * Es la que usa `update.sh` cuando hay que regresar, y la unica que puede:
     * el rol de la aplicacion no es dueño de las tablas (ADR-033), asi que un
     * `DROP TABLE` por su conexion falla con «must be owner of table» — que es
     * exactamente la separacion de privilegios que se quiere. Se invoca
     * `migrate:rollback` y no `down()` a mano, por lo mismo que las hermanas de
     * `Integration/Product`: probar el metodo no es probar el camino por el que
     * se ejecuta de verdad.
     */
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    expect($migrator->getSchemaBuilder()->hasTable('report_exports'))->toBeTrue();

    try {
        // CUANTOS pasos, y no «uno»: cada migracion nueva que se añada despues
        // desplaza a esta, y un `--step=1` fijo acabaria probando el `down()` de
        // otra sin que nadie lo notara.
        $steps = reportExportsMigrationStepsBack($migrator, '2026_09_22_110000_report_exports');

        expect($steps)->toBeGreaterThan(0);

        [$exitCode] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($exitCode)->toBe(0)
            ->and($migrator->getSchemaBuilder()->hasTable('report_exports'))->toBeFalse();

        [$forward] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0);
    } finally {
        // Idempotente: si el `migrate` de arriba ya corrio, este no hace nada. La
        // base de pruebas es compartida y dejarla sin la tabla convertiria un
        // fallo de asercion en un fallo en cascada de todo lo que venga despues.
        Commands::run('migrate --database='.$name);
    }

    expect($migrator->getSchemaBuilder()->hasTable('report_exports'))->toBeTrue()
        // Y vuelve **vacia**: es una migracion de creacion pura, no arrastra
        // datos de nadie.
        ->and($migrator->table('report_exports')->count())->toBe(0);
})->group('RF-IN-06', 'RNF-D-04');

/**
 * Cuantos pasos de `migrate:rollback` hacen falta para deshacer esta migracion,
 * contando desde la ultima aplicada.
 *
 * Se duplica a proposito en vez de compartirse con sus gemelas de
 * `Integration/Product`: una funcion global de Pest definida en otro fichero
 * solo existe si ese fichero se ha cargado, y estas pruebas tienen que poder
 * ejecutarse sueltas.
 */
function reportExportsMigrationStepsBack(ConnectionInterface $connection, string $migration): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === $migration) {
            return $steps;
        }
    }

    return 0;
}

it('borra los ficheros huerfanos que ninguna fila menciona', function (): void {
    /*
     * **El fichero que nadie sabe que existe** (hallazgo de la revision). La ruta
     * solo se guarda al cerrar la fila, asi que un trabajo que muere antes
     * —`$timeout` agotado, el trabajador sin memoria, un `SIGTERM` durante un
     * despliegue— deja en el disco media plantilla con sus horas y en la base de
     * datos una fila que nunca la menciono. La purga por caducidad no lo veia:
     * solo recorre filas `completed`.
     *
     * Aqui se simula exactamente eso: un directorio con un fichero dentro cuyo
     * `uuid` no corresponde a ninguna fila con derecho a fichero.
     */
    $viva = ReportExports::completedFor(cuentaQuePide());

    $raiz = Config::string('reporting.export.path');
    $huerfano = $raiz.\DIRECTORY_SEPARATOR.'019a0000-0000-7000-8000-00000000dead';

    mkdir($huerfano, 0700, true);
    file_put_contents($huerfano.\DIRECTORY_SEPARATOR.'kronoqr-horas-2026-03-01_2026-03-31.csv', "a medias\n");

    $resultado = app(PurgeExpiredReportExports::class)->handle();

    expect($resultado->orphans)->toBe(1)
        ->and(is_dir($huerfano))->toBeFalse()
        // Y **no** toca la que si tiene derecho a su fichero: el barrido va al
        // reves —lo que hay menos lo que puede estar— y esa resta tiene que dejar
        // fuera lo legitimo.
        ->and(is_file((string) $viva->filePath))->toBeTrue()
        ->and($resultado->purged)->toBe(0);
})->group('RF-IN-06');

it('el trabajo que muere sin poder cerrarse borra su propio fichero a medias', function (): void {
    /*
     * El camino normal del hallazgo anterior: `failed()` corre cuando el trabajo
     * muere de una forma que el caso de uso no puede atrapar. Marca la fila y
     * ahora **tambien borra su directorio**, que es lo unico que sabe de el —la
     * ruta nunca llego a guardarse—.
     *
     * El barrido de huerfanos de la purga sigue existiendo como red de debajo,
     * para cuando ni siquiera se llega a ejecutar este metodo (un `SIGKILL`).
     */
    $export = ReportExports::pendingFor(cuentaQuePide());

    $directorio = Config::string('reporting.export.path').\DIRECTORY_SEPARATOR.$export->uuid;

    mkdir($directorio, 0700, true);
    file_put_contents($directorio.\DIRECTORY_SEPARATOR.'kronoqr-horas-2026-03-01_2026-03-31.csv', "a medias\n");

    (new GenerateReportExportJob($export->uuid))->failed(new RuntimeException('el trabajador murio'));

    expect(is_dir($directorio))->toBeFalse()
        ->and(ReportExports::find($export->uuid)->status->value)->toBe('failed')
        ->and(ReportExports::find($export->uuid)->failureReason?->value)->toBe('unexpected');
})->group('RF-IN-06');

it('una generacion fallida mueve la metrica de trabajos fallidos', function (): void {
    /*
     * `queue_jobs_failed_total{job}` solo se mueve sola cuando el trabajo **deja
     * salir** su excepcion, y este la captura a proposito: con
     * `QUEUE_CONNECTION=sync` —configuracion legitima de una instalacion
     * pequeña— dejarla salir convertiria el `202` de una peticion que hizo lo que
     * prometio en un `500`.
     *
     * El precio era que una generacion fallida no movia ninguna serie: la fila
     * quedaba en `failed` y en el panel de observabilidad no pasaba nada, que es
     * justo el caso que la alerta de cola existe para ver.
     */
    $metricas = new RecordingQueuedJobFailureMetrics;

    app()->instance(QueuedJobFailureMetrics::class, $metricas);
    // Un `uuid` que no existe: el caso de uso lanza y el trabajo lo captura.
    (new GenerateReportExportJob('019a0000-0000-7000-8000-0000000000ff'))->handle(
        app(GenerateReportExportHandler::class),
        app(LocalePolicyProvider::class),
        $metricas,
        app(),
        app(LoggerInterface::class),
    );

    expect($metricas->failures)->toBe(['GenerateReportExportJob']);
})->group('RF-IN-06');

it('rechaza desde el esquema una fila purgada que conserve datos personales', function (): void {
    /*
     * RL-11. La minimizacion al purgar es una promesa de proteccion de datos y
     * por eso vive en el `CHECK` y no solo en `ReportExport::purge()`: un camino
     * nuevo que marcara `purged` sin vaciar el alcance y los dos filtros dejaria
     * datos personales conservados **sin plazo**, y nadie lo notaria.
     *
     * Es tambien la razon por la que `report_exports` no entra en
     * `RetentionScope`: pasado su plazo, la fila deja de contener datos
     * personales, asi que conservarla indefinidamente es gratis y sigue
     * contestando «¿salio de aqui un informe y a peticion de quien?».
     */
    $export = ReportExports::completedFor(cuentaQuePide());

    expect(static fn () => DB::table('report_exports')
        ->where('id', $export->id)
        ->update(['status' => 'purged', 'purged_at' => now(), 'file_path' => null]))
        ->toThrow(QueryException::class);
})->group('RL-11', 'RF-IN-06');

it('la purga deja la fila sin alcance y sin los filtros por persona', function (): void {
    // La otra mitad: que el camino normal **si** minimiza. Lo que se queda es lo
    // que describe QUE se genero —periodo, granularidad, formato, huella— y ya no
    // DE QUIEN.
    $export = ReportExports::completedFor(
        cuentaQuePide(),
        expiresAt: app(Clock::class)->now()->modify('-1 hour'),
    );

    app(PurgeExpiredReportExports::class)->handle();

    $purgada = ReportExports::find($export->uuid);

    expect($purgada->scope)->toBeNull()
        ->and($purgada->parameters->employeeUuid)->toBeNull()
        ->and($purgada->parameters->departmentId)->toBeNull()
        ->and($purgada->parameters->from)->toBe('2026-03-01')
        ->and($purgada->parameters->to)->toBe('2026-03-31')
        ->and($purgada->sha256)->toHaveLength(64);
})->group('RL-11', 'RF-IN-06');
