<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
use App\Modules\Reporting\Domain\ValueObject\ReportExportMaintenance;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Shared\RecordingGeneratedFileMetrics;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La conciliacion fila ↔ fichero de los informes en diferido (ADR-045 §a-§e;
 * condiciones C1, C3, C5 y C9; doc 01 §5.5, `report_exports`).
 *
 * Mismo algoritmo que la exportacion integra con la forma de esta clase: cada
 * informe es un directorio `<uuid>/` con un fichero dentro. Como alli, la edad
 * no se fabrica envejeciendo el fichero —`ctime` no se puede fijar— sino
 * adelantando el reloj inyectado con `relojDeInformesDentroDe()`.
 */

const REPORT_EXPORT_RECONCILIATION_STALE = 3600;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    relojDeInformesDentroDe(0);
    WorkforceFixtures::site();
    Config::set('reporting.export.stale_after_seconds', REPORT_EXPORT_RECONCILIATION_STALE);
    Config::set('reporting.export.retention_days', 7);
    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    ReportExports::cleanUpTemporaryPath();
    GeneratedFilesSandbox::cleanUp();
});

function relojDeInformesDentroDe(int $seconds): void
{
    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + $seconds));
}

function raizDeInformes(): string
{
    return Config::string('reporting.export.path');
}

function purgarInformes(): ReportExportMaintenance
{
    return app(PurgeExpiredReportExports::class)->handle();
}

function quienPideElInforme(): int
{
    return ManagementUsers::withRole(UserRole::RRHH)->id;
}

it('una fila completed sin fichero y ya vencida pasa a purged sin asiento', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $informe = ReportExports::completedFor(quienPideElInforme(), expiresAt: app(Clock::class)->now()->modify('-1 hour'));
    unlink((string) $informe->filePath);

    purgarInformes();

    expect(ReportExports::find($informe->uuid)->status->value)->toBe('purged')
        ->and(DB::table('audit_log')->where('action', 'report_export.file_missing')->count())->toBe(0)
        ->and($metricas->missing)->toBe([]);
})->group('RF-IN-06', 'RL-15');

it('un fichero que desaparece antes de caducar deja asiento sin ruta, sube la metrica y minimiza la fila', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $informe = ReportExports::completedFor(quienPideElInforme());
    unlink((string) $informe->filePath);

    $resultado = purgarInformes();
    $fila = ReportExports::find($informe->uuid);

    expect($resultado->missing)->toBe(1)
        ->and($fila->status->value)->toBe('purged')
        ->and($fila->filePath)->toBeNull()
        // Minimizada como cualquier purga (RL-11).
        ->and($fila->scope)->toBeNull()
        ->and($metricas->missing)->toBe(['report_export']);

    $asiento = DB::table('audit_log')->where('action', 'report_export.file_missing')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{actor_type: string, subject_type: ?string, payload: string} $asiento */
    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('system')
        ->and($asiento->subject_type)->toBe('report_export')
        ->and(array_keys($payload))->toEqualCanonicalizing(['report_export_uuid', 'expires_at', 'detected_at'])
        ->and($payload['report_export_uuid'])->toBe($informe->uuid)
        ->and($asiento->payload)->not->toContain(raizDeInformes())
        ->and($asiento->payload)->not->toContain((string) $informe->fileName);
})->group('RF-IN-06', 'RL-15', 'RL-11');

it('borra entero un <uuid>/ sin fila al superar el plazo de retencion, y no antes', function (): void {
    $huerfano = raizDeInformes().'/'.Str::uuid7()->toString();
    GeneratedFilesSandbox::file($huerfano.'/kronoqr-horas-2026-03-01_2026-03-31.csv');

    relojDeInformesDentroDe(7 * 86400 - 60);
    expect(purgarInformes()->orphans)->toBe(0)
        ->and(is_dir($huerfano))->toBeTrue();

    relojDeInformesDentroDe(7 * 86400 + 60);
    expect(purgarInformes()->orphans)->toBe(1)
        ->and(is_dir($huerfano))->toBeFalse();
})->group('RF-IN-06', 'RL-11');

it('borra a las 2 × stale_after el directorio de una fila que no llego a completed', function (): void {
    relojDeInformesDentroDe(2 * REPORT_EXPORT_RECONCILIATION_STALE + 60);
    $fallida = ReportExports::pendingFor(quienPideElInforme());
    DB::table('report_exports')->where('id', $fallida->id)->update([
        'status' => 'failed', 'failed_at' => '2026-03-08 09:10:00+00', 'failure_reason' => 'unexpected',
    ]);
    $directorio = raizDeInformes().'/'.$fallida->uuid;
    GeneratedFilesSandbox::file($directorio.'/kronoqr-horas-2026-03-01_2026-03-31.csv', "a medias\n");

    purgarInformes();

    expect(is_dir($directorio))->toBeFalse();
})->group('RF-IN-06', 'RL-11');

it('no toca el directorio de un informe en curso ni el de uno vigente, por viejos que sean', function (): void {
    relojDeInformesDentroDe(30 * 86400);
    $enCurso = ReportExports::pendingFor(ManagementUsers::withRole(UserRole::ADMIN)->id);
    DB::table('report_exports')->where('id', $enCurso->id)->update(['requested_at' => now()]);
    $directorioEnCurso = raizDeInformes().'/'.$enCurso->uuid;
    GeneratedFilesSandbox::file($directorioEnCurso.'/parcial.csv');
    $vigente = ReportExports::completedFor(quienPideElInforme());

    purgarInformes();

    expect(is_dir($directorioEnCurso))->toBeTrue()
        ->and(is_file((string) $vigente->filePath))->toBeTrue();
})->group('RF-IN-06');

it('con REPORTING_EXPORT_PATH apuntado a storage/app no borra nada de las demas clases', function (): void {
    // El caso de C3: antes cualquier subdirectorio pasaba por un `uuid`.
    $storageApp = GeneratedFilesSandbox::directory('storage-app');
    $zip = GeneratedFilesSandbox::file($storageApp.'/exports/kronoqr-export-2.2.0-X.zip');
    $legal = GeneratedFilesSandbox::file($storageApp.'/legal-exports/registro-horario-2026-01-01_2026-01-31.csv');
    $paquete = GeneratedFilesSandbox::file($storageApp.'/diagnostics/kronoqr-diagnostics-2.2.0-X.json');
    $estado = GeneratedFilesSandbox::file($storageApp.'/telemetry/state.json');
    Config::set('reporting.export.path', $storageApp);

    relojDeInformesDentroDe(365 * 86400);
    purgarInformes();

    expect([is_file($zip), is_file($legal), is_file($paquete), is_file($estado)])->toBe([true, true, true, true]);
})->group('RF-IN-06');

it('aborta un <uuid>/ con un enlace simbolico dentro y no sigue el enlace', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $ajeno = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('copias').'/daily.dump.enc');
    $huerfano = raizDeInformes().'/'.Str::uuid7()->toString();
    GeneratedFilesSandbox::file($huerfano.'/informe.csv');
    symlink($ajeno, $huerfano.'/enlace.csv');

    relojDeInformesDentroDe(30 * 86400);
    purgarInformes();

    expect(is_file($ajeno))->toBeTrue()
        ->and(is_file($huerfano.'/informe.csv'))->toBeTrue()
        ->and($metricas->refused)->toBe(['report_export']);
})->group('RF-IN-06');

it('una fila que apunta fuera de su raiz pasa a purged y el fichero de fuera sigue ahi', function (): void {
    $fuera = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('fuera').'/'.Str::uuid7()->toString().'/x.csv');
    $informe = ReportExports::completedFor(quienPideElInforme(), expiresAt: app(Clock::class)->now()->modify('-1 hour'));
    DB::table('report_exports')->where('id', $informe->id)->update(['file_path' => $fuera]);

    purgarInformes();

    expect(ReportExports::find($informe->uuid)->status->value)->toBe('purged')
        ->and(is_file($fuera))->toBeTrue();
})->group('RF-IN-06');

it('el borrado por uuid del trabajo fallido no sale de su raiz', function (): void {
    $storageApp = GeneratedFilesSandbox::directory('vecinos');
    $vecino = GeneratedFilesSandbox::file($storageApp.'/exports/kronoqr-export-2.2.0-X.zip');
    mkdir($storageApp.'/reports', 0o700, true);
    Config::set('reporting.export.path', $storageApp.'/reports');

    app(ReportExportStorage::class)->deleteAllFor('../exports');

    expect(is_file($vecino))->toBeTrue();
})->group('RF-IN-06');

it('el comando avisa de los ficheros desaparecidos sin nombres ni rutas', function (): void {
    $informe = ReportExports::completedFor(quienPideElInforme());
    unlink((string) $informe->filePath);

    [$codigo, $salida] = Commands::run('reporting:purge-expired-exports');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('report_export.file_missing')
        ->and($salida)->not->toContain((string) $informe->fileName)
        ->and($salida)->not->toContain($informe->uuid);
})->group('RF-IN-06', 'RL-15');
