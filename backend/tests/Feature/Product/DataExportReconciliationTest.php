<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\UseCase\PurgeExpiredDataExportsHandler;
use App\Modules\Product\Domain\ValueObject\DataExportMaintenance;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Shared\RecordingGeneratedFileMetrics;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La conciliacion fila ↔ fichero de la exportacion integra (ADR-045 §a-§e;
 * condiciones C1, C3, C5 y C9; doc 01 §5.5).
 *
 * El ZIP vive en el volumen `app-storage`, que no entra en la copia: una
 * restauracion devuelve filas `completed` sin ZIP y deja ZIP sin fila, y un
 * trabajo que muere a mitad deja su `.work-<uuid>/` con TODOS los datos en
 * claro. La purga horaria lo concilia en los dos sentidos, sin salir nunca de
 * `PRODUCT_DATA_EXPORT_PATH`.
 *
 * ## Como se envejece un fichero en una prueba
 *
 * La edad es `max(mtime, ctime)` y `ctime` no se puede fijar: es el instante en
 * que se creo el fichero, es decir, ahora. Asi que no se envejece el fichero,
 * se **adelanta el reloj** inyectado. `relojDentroDe(N)` congela el reloj N
 * segundos despues del reloj real: un fichero recien creado tiene entonces N
 * segundos de edad.
 */

const DATA_EXPORT_RECONCILIATION_STALE = 3600;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    relojDentroDe(0);
    WorkforceFixtures::site();
    LicenseKeys::install();
    config(['product.data_export_stale_after_seconds' => DATA_EXPORT_RECONCILIATION_STALE]);
    config(['product.data_export_retention_days' => 7]);

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
    GeneratedFilesSandbox::cleanUp();
});

function relojDentroDe(int $seconds): void
{
    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + $seconds));
}

function raizDeExportaciones(): string
{
    return config()->string('product.data_export_path');
}

function purgarExportaciones(): DataExportMaintenance
{
    return app(PurgeExpiredDataExportsHandler::class)->handle();
}

/** @return list<object> */
function asientosFileMissing(): array
{
    return array_values(DB::table('audit_log')->where('action', 'data_export.file_missing')->get()->all());
}

it('una fila completed sin fichero y ya vencida pasa a purged sin asiento', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $exportacion = DataExports::completed(expiresAt: app(Clock::class)->now()->modify('-1 hour'));
    unlink((string) $exportacion->filePath);

    purgarExportaciones();

    // Caducidad normal: el plazo ya consta en la fila. No es un evento de seguridad.
    expect(DataExports::find($exportacion->uuid)->status->value)->toBe('purged')
        ->and(asientosFileMissing())->toBe([])
        ->and($metricas->missing)->toBe([]);
})->group('RF-PD-14', 'RL-20', 'RL-15');

it('una fila completed cuyo fichero desaparece antes de caducar deja asiento sin ruta y sube la metrica', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $exportacion = DataExports::completed();
    unlink((string) $exportacion->filePath);

    $resultado = purgarExportaciones();

    $fila = DataExports::find($exportacion->uuid);

    expect($resultado->missing)->toBe(1)
        ->and($fila->status->value)->toBe('purged')
        ->and($fila->filePath)->toBeNull()
        ->and($fila->purgedAt)->not->toBeNull()
        // La fila NUNCA se borra (regla dura 5): conserva nombre, huella y recuentos.
        ->and($fila->sha256)->toBe($exportacion->sha256)
        ->and($metricas->missing)->toBe(['data_export']);

    $asientos = asientosFileMissing();

    expect($asientos)->toHaveCount(1);

    /** @var object{actor_type: string, subject_type: ?string, payload: string} $asiento */
    $asiento = $asientos[0];
    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('system')
        ->and($asiento->subject_type)->toBe('data_export')
        // JSONB no conserva el orden de las claves: se compara el conjunto.
        ->and(array_keys($payload))->toEqualCanonicalizing(['data_export_uuid', 'expires_at', 'detected_at'])
        ->and($payload['data_export_uuid'])->toBe($exportacion->uuid)
        ->and($payload['expires_at'])->toBe('2036-01-01T00:00:00+00:00')
        // Regla dura 21: ni la ruta ni el nombre del fichero.
        ->and($asiento->payload)->not->toContain(raizDeExportaciones())
        ->and($asiento->payload)->not->toContain((string) $exportacion->fileName);
})->group('RF-PD-14', 'RL-20', 'RL-15');

it('el asiento solo se escribe una vez: la fila ya purgada no vuelve a contar', function (): void {
    $exportacion = DataExports::completed();
    unlink((string) $exportacion->filePath);

    purgarExportaciones();
    $segunda = purgarExportaciones();

    expect($segunda->missing)->toBe(0)
        ->and(asientosFileMissing())->toHaveCount(1);
})->group('RF-PD-14', 'RL-15');

it('una fila que apunta fuera de su raiz pasa a purged y el fichero de fuera sigue ahi', function (): void {
    // Una fila alterada que pretende que la purga se lleve una copia de BACKUP_PATH.
    $metricas = RecordingGeneratedFileMetrics::install();
    $fuera = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('backups').'/kronoqr-export-2.1.0-COPIA.zip');
    $exportacion = DataExports::completed(expiresAt: app(Clock::class)->now()->modify('-1 hour'));
    DB::table('data_exports')->where('uuid', $exportacion->uuid)->update(['file_path' => $fuera]);

    purgarExportaciones();

    expect(DataExports::find($exportacion->uuid)->status->value)->toBe('purged')
        ->and(is_file($fuera))->toBeTrue()
        ->and($metricas->refused)->toBe(['data_export']);
})->group('RF-PD-14', 'RL-20');

it('una fila vigente que apunta fuera de su raiz pasa a purged sin asiento y sin borrar nada', function (): void {
    $fuera = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('otra').'/kronoqr-export-2.1.0-X.zip');
    $exportacion = DataExports::completed();
    DB::table('data_exports')->where('uuid', $exportacion->uuid)->update(['file_path' => $fuera]);

    $resultado = purgarExportaciones();

    expect(DataExports::find($exportacion->uuid)->status->value)->toBe('purged')
        ->and($resultado->missing)->toBe(0)
        ->and(asientosFileMissing())->toBe([])
        ->and(is_file($fuera))->toBeTrue();
})->group('RF-PD-14', 'RL-20');

it('el escritor tampoco borra una ruta fuera de su raiz', function (): void {
    $fuera = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('copias').'/kronoqr-export-2.1.0-Y.zip');

    expect(app(DataExportArchiveWriter::class)->delete($fuera))->toBeFalse()
        ->and(is_file($fuera))->toBeTrue();
})->group('RF-PD-14');

it('borra un .work-<uuid> viejo sin fila viva y deja uno reciente', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $viejo = raizDeExportaciones().'/.work-'.Str::uuid7()->toString();
    GeneratedFilesSandbox::file($viejo.'/employees.csv', "dni;nombre\n");
    GeneratedFilesSandbox::file($viejo.'/manifest.json', '{}');

    // 2 × stale_after mas un segundo: ya no puede ser un trabajo en curso.
    relojDentroDe(2 * DATA_EXPORT_RECONCILIATION_STALE + 1);

    $reciente = raizDeExportaciones().'/.work-'.Str::uuid7()->toString();
    GeneratedFilesSandbox::file($reciente.'/employees.csv', "dni;nombre\n");
    // El reciente nace en el reloj real, que va dos horas por detras del
    // inyectado. Para que ese reloj lo vea recien escrito, su ultima escritura
    // (`mtime`) se pone en su «ahora»: un segundo antes del reloj inyectado.
    touch($reciente, time() + 2 * DATA_EXPORT_RECONCILIATION_STALE);
    touch($reciente.'/employees.csv', time() + 2 * DATA_EXPORT_RECONCILIATION_STALE);

    $resultado = purgarExportaciones();

    expect(is_dir($viejo))->toBeFalse()
        ->and(is_dir($reciente))->toBeTrue()
        ->and($resultado->orphans)->toBe(1)
        ->and($metricas->orphans)->toBe(['data_export_work']);
})->group('RF-PD-14', 'RL-20', 'RL-11');

it('no toca el .work-<uuid> de la exportacion en curso aunque sea viejo', function (): void {
    relojDentroDe(2 * DATA_EXPORT_RECONCILIATION_STALE + 60);
    $enCurso = DataExports::inProgress('running');
    $espacio = raizDeExportaciones().'/.work-'.$enCurso->uuid;
    GeneratedFilesSandbox::file($espacio.'/employees.csv');

    // El fichero nacio en el reloj real; el inyectado va dos horas por delante,
    // pero la fila sigue viva (su `started_at` es el del reloj inyectado).
    purgarExportaciones();

    expect(is_dir($espacio))->toBeTrue();
})->group('RF-PD-14', 'RL-20');

it('borra un temporal de ZipArchive viejo', function (): void {
    $temporal = GeneratedFilesSandbox::file(raizDeExportaciones().'/kronoqr-export-2.2.0-20261002T120000Z.zip.Ab12Cd');

    relojDentroDe(2 * DATA_EXPORT_RECONCILIATION_STALE + 1);
    purgarExportaciones();

    expect(is_file($temporal))->toBeFalse();
})->group('RF-PD-14', 'RL-11');

it('no borra un temporal de ZipArchive mientras puede ser un sellado en curso', function (): void {
    $temporal = GeneratedFilesSandbox::file(raizDeExportaciones().'/kronoqr-export-2.2.0-20261002T120000Z.zip.Ab12Cd');

    relojDentroDe(2 * DATA_EXPORT_RECONCILIATION_STALE - 60);
    purgarExportaciones();

    expect(is_file($temporal))->toBeTrue();
})->group('RF-PD-14');

it('borra un ZIP sin fila al superar el plazo de retencion, y no antes', function (): void {
    // Lo que deja una restauracion: el ZIP de una exportacion posterior a la copia.
    $huerfano = GeneratedFilesSandbox::file(raizDeExportaciones().'/kronoqr-export-2.2.0-20261002T120000Z.zip');

    relojDentroDe(7 * 86400 - 60);
    purgarExportaciones();

    expect(is_file($huerfano))->toBeTrue();

    relojDentroDe(7 * 86400 + 60);
    purgarExportaciones();

    expect(is_file($huerfano))->toBeFalse();
})->group('RF-PD-14', 'RL-20', 'RL-11');

it('no borra el ZIP de una fila vigente por viejo que sea', function (): void {
    $exportacion = DataExports::completed();

    relojDentroDe(30 * 86400);
    purgarExportaciones();

    expect(is_file((string) $exportacion->filePath))->toBeTrue()
        ->and(DataExports::find($exportacion->uuid)->status->value)->toBe('completed');
})->group('RF-PD-14', 'RL-20');

it('mide la edad con ctime cuando mtime esta en el futuro', function (): void {
    // `touch -d 2099` no hace inmortal a un ZIP huerfano: manda ctime.
    $huerfano = GeneratedFilesSandbox::file(raizDeExportaciones().'/kronoqr-export-2.2.0-20261002T120000Z.zip');
    touch($huerfano, time() + 365 * 86400);

    relojDentroDe(7 * 86400 + 60);
    purgarExportaciones();

    expect(is_file($huerfano))->toBeFalse();
})->group('RF-PD-14', 'RL-11');

it('no adelanta el borrado de un fichero recien escrito con un mtime viejo', function (): void {
    // `touch -d 2020` sobre un ZIP recien escrito: ctime dice que es de ahora.
    $huerfano = GeneratedFilesSandbox::file(raizDeExportaciones().'/kronoqr-export-2.2.0-20261002T120000Z.zip');
    touch($huerfano, time() - 365 * 86400);

    purgarExportaciones();

    expect(is_file($huerfano))->toBeTrue();
})->group('RF-PD-14', 'RL-11');

it('aborta un .work-<uuid> con un subdirectorio dentro y no borra nada de el', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $espacio = raizDeExportaciones().'/.work-'.Str::uuid7()->toString();
    GeneratedFilesSandbox::file($espacio.'/employees.csv');
    GeneratedFilesSandbox::file($espacio.'/anidado/otro.csv');

    relojDentroDe(2 * DATA_EXPORT_RECONCILIATION_STALE + 60);
    purgarExportaciones();

    expect(is_file($espacio.'/employees.csv'))->toBeTrue()
        ->and(is_file($espacio.'/anidado/otro.csv'))->toBeTrue()
        ->and($metricas->refused)->toBe(['data_export_work']);
})->group('RF-PD-14');

it('aborta un .work-<uuid> con un enlace simbolico dentro y no sigue el enlace', function (): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $ajeno = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('ajeno').'/copia.dump');
    $espacio = raizDeExportaciones().'/.work-'.Str::uuid7()->toString();
    GeneratedFilesSandbox::file($espacio.'/employees.csv');
    symlink($ajeno, $espacio.'/enlace.csv');

    relojDentroDe(2 * DATA_EXPORT_RECONCILIATION_STALE + 60);
    purgarExportaciones();

    expect(is_file($ajeno))->toBeTrue()
        ->and(is_link($espacio.'/enlace.csv'))->toBeTrue()
        ->and(is_file($espacio.'/employees.csv'))->toBeTrue()
        ->and($metricas->refused)->toBe(['data_export_work']);
})->group('RF-PD-14');

it('no sigue un enlace simbolico con nombre de ZIP en la raiz', function (): void {
    $ajeno = GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('ajeno').'/copia.dump');
    $enlace = raizDeExportaciones().'/kronoqr-export-2.2.0-20261002T120000Z.zip';
    mkdir(raizDeExportaciones(), 0o700, true);
    symlink($ajeno, $enlace);

    relojDentroDe(30 * 86400);
    purgarExportaciones();

    expect(is_file($ajeno))->toBeTrue();
})->group('RF-PD-14');

it('no toca nada de la raiz que no case con el patron de una clase', function (): void {
    $ajeno = GeneratedFilesSandbox::file(raizDeExportaciones().'/notas-del-administrador.txt');
    $copia = GeneratedFilesSandbox::file(raizDeExportaciones().'/copia/kronoqr-export-2.2.0-X.zip');

    relojDentroDe(365 * 86400);
    purgarExportaciones();

    expect(is_file($ajeno))->toBeTrue()
        // Un solo nivel: lo que hay debajo de un subdirectorio no existe para la purga.
        ->and(is_file($copia))->toBeTrue();
})->group('RF-PD-14');

it('el comando dice en voz alta cuantas desaparecieron, sin nombres ni rutas', function (): void {
    $exportacion = DataExports::completed();
    unlink((string) $exportacion->filePath);

    [$codigo, $salida] = Commands::run('product:export-all --purge');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('data_export.file_missing')
        ->and($salida)->not->toContain((string) $exportacion->fileName)
        ->and($salida)->not->toContain(raizDeExportaciones());
})->group('RF-PD-14', 'RL-15');
