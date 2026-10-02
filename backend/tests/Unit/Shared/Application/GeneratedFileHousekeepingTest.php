<?php

declare(strict_types=1);

use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\GeneratedFileMetrics;
use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\FileTimestamps;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileRemoval;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileShape;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;
use Psr\Log\AbstractLogger;

/*
 * La conciliacion compartida de ficheros generados (ADR-045 §b, §e, §h; C5, C10).
 *
 * Sin disco: el almacen es un doble en memoria. Lo que se prueba es la DECISION
 * —que se borra, que se protege, que se cuenta— y que ni el log ni la metrica
 * llevan un `uuid`, un nombre de fichero o una ruta (regla dura 21).
 */

const GENERATED_FILE_HOUSEKEEPING_NOW = '2026-10-02T12:00:00+00:00';

const GENERATED_FILE_HOUSEKEEPING_UUID = '019a0000-0000-7000-8000-00000000dead';

/** Un almacen en memoria: entradas con su edad en segundos y lo que pasa al borrarlas. */
final class InMemoryGeneratedFileStore implements GeneratedFileStore
{
    /** @var list<string> */
    public array $removed = [];

    /**
     * @param  array<string, int>  $ages  nombre => segundos desde el ultimo toque
     * @param  array<string, GeneratedFileRemoval>  $outcomes
     */
    public function __construct(
        private array $ages,
        private array $outcomes = [],
        private RecordedFileLocation $location = RecordedFileLocation::Present,
        private GeneratedFileRemoval $discard = GeneratedFileRemoval::Removed,
    ) {}

    public function entries(GeneratedFileArea $area): array
    {
        $now = (new DateTimeImmutable(GENERATED_FILE_HOUSEKEEPING_NOW))->getTimestamp();
        $entries = [];

        foreach ($this->ages as $name => $age) {
            $entries[] = new GeneratedFileEntry($name, [FileTimestamps::fromEpochSeconds($now - $age, $now - $age)]);
        }

        return $entries;
    }

    public function remove(GeneratedFileArea $area, string $name): GeneratedFileRemoval
    {
        $this->removed[] = $name;

        return $this->outcomes[$name] ?? GeneratedFileRemoval::Removed;
    }

    public function locate(GeneratedFileArea $area, string $recordedPath): RecordedFileLocation
    {
        return $this->location;
    }

    public function discard(GeneratedFileArea $area, string $recordedPath): GeneratedFileRemoval
    {
        return $this->discard;
    }

    public function rootExists(GeneratedFileArea $area): bool
    {
        return $this->rootAvailable;
    }

    public bool $rootAvailable = true;
}

/** Ejecuta el trabajo sin base de datos y cuenta cuantas veces se tomo el «candado». */
final class PassThroughLedgerWrite implements SerializedLedgerWrite
{
    public int $locks = 0;

    public function withChainLock(callable $work): mixed
    {
        $this->locks++;

        return $work();
    }
}

final class RecordingGeneratedFileMetricsDouble implements GeneratedFileMetrics
{
    /** @var list<string> */
    public array $calls = [];

    public function orphanRemoved(GeneratedFileClass $class): void
    {
        $this->calls[] = 'orphan:'.$class->value;
    }

    public function refused(GeneratedFileClass $class): void
    {
        $this->calls[] = 'refused:'.$class->value;
    }

    public function missing(GeneratedFileClass $class): void
    {
        $this->calls[] = 'missing:'.$class->value;
    }

    public function removeFailed(GeneratedFileClass $class): void
    {
        $this->calls[] = 'remove_failed:'.$class->value;
    }

    public function overdue(GeneratedFileClass $class, int $count): void
    {
        $this->calls[] = 'overdue:'.$class->value.'='.$count;
    }
}

final class RecordingHousekeepingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    /** @param  array<mixed>  $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : '', (string) $message, $context];
    }
}

function areaDePrueba(GeneratedFileClass $clase = GeneratedFileClass::ReportExport): GeneratedFileArea
{
    return new GeneratedFileArea($clase, '/srv/raiz', '/^.+$/D', GeneratedFileShape::Directory);
}

function ahoraDeLimpieza(): DateTimeImmutable
{
    return new DateTimeImmutable(GENERATED_FILE_HOUSEKEEPING_NOW);
}

it('borra solo lo que ninguna fila protege y supera su edad minima', function (): void {
    $almacen = new InMemoryGeneratedFileStore(['protegido' => 99_999, 'viejo' => 7_201, 'reciente' => 60, 'justo' => 7_200]);
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping($almacen, $metricas, new RecordingHousekeepingLogger, new PassThroughLedgerWrite);

    $borrados = $limpieza->sweepOrphans(
        areaDePrueba(),
        static fn (GeneratedFileEntry $entry): ?int => $entry->name === 'protegido' ? null : 7_200,
        ahoraDeLimpieza(),
    );

    // `justo` empata con la edad minima y se conserva: «supera», no «alcanza».
    expect($borrados)->toBe(1)
        ->and($almacen->removed)->toBe(['viejo'])
        ->and($metricas->calls)->toBe(['orphan:report_export']);
})->group('RF-IN-06', 'RL-11');

it('cuenta los rechazos y no los cuenta como borrados', function (): void {
    $almacen = new InMemoryGeneratedFileStore(
        ['con-enlace' => 99_999, 'sin-permiso' => 99_999, 'ya-no-esta' => 99_999],
        [
            'con-enlace' => GeneratedFileRemoval::Refused,
            'sin-permiso' => GeneratedFileRemoval::Failed,
            'ya-no-esta' => GeneratedFileRemoval::Absent,
        ],
    );
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping($almacen, $metricas, new RecordingHousekeepingLogger, new PassThroughLedgerWrite);

    $borrados = $limpieza->sweepOrphans(areaDePrueba(), static fn (): int => 1, ahoraDeLimpieza());

    expect($borrados)->toBe(0)
        ->and($metricas->calls)->toBe(['refused:report_export', 'remove_failed:report_export']);
})->group('RF-IN-06');

it('el log de los huerfanos lleva la clase y la cifra, nunca un uuid ni una ruta', function (): void {
    $logger = new RecordingHousekeepingLogger;
    $limpieza = new GeneratedFileHousekeeping(
        new InMemoryGeneratedFileStore([GENERATED_FILE_HOUSEKEEPING_UUID => 99_999]),
        new RecordingGeneratedFileMetricsDouble,
        $logger,
        new PassThroughLedgerWrite,
    );

    $limpieza->sweepOrphans(areaDePrueba(), static fn (): int => 1, ahoraDeLimpieza());

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0][1])->toBe('generated_files.orphans_removed')
        ->and($logger->records[0][2]['class'])->toBe('report_export')
        ->and($logger->records[0][2]['count'])->toBe(1);

    $volcado = json_encode($logger->records, JSON_THROW_ON_ERROR);

    expect($volcado)->not->toContain(GENERATED_FILE_HOUSEKEEPING_UUID)
        ->and($volcado)->not->toContain('/srv/raiz');
})->group('RF-IN-06', 'RL-15');

it('sin nada que borrar no escribe ningun log', function (): void {
    $logger = new RecordingHousekeepingLogger;
    $limpieza = new GeneratedFileHousekeeping(new InMemoryGeneratedFileStore([]), new RecordingGeneratedFileMetricsDouble, $logger, new PassThroughLedgerWrite);

    expect($limpieza->sweepOrphans(areaDePrueba(), static fn (): int => 1, ahoraDeLimpieza()))->toBe(0)
        ->and($logger->records)->toBe([]);
})->group('RF-IN-06');

it('un fichero de fila que cae fuera de su raiz sube la metrica de rechazos', function (): void {
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping(
        new InMemoryGeneratedFileStore([], [], RecordedFileLocation::OutsideArea, GeneratedFileRemoval::Refused),
        $metricas,
        new RecordingHousekeepingLogger,
        new PassThroughLedgerWrite,
    );

    expect($limpieza->locateRecorded(areaDePrueba(), '/etc/passwd'))->toBe(RecordedFileLocation::OutsideArea)
        ->and($limpieza->discardRecorded(areaDePrueba(), '/etc/passwd'))->toBe(GeneratedFileRemoval::Refused)
        ->and($metricas->calls)->toBe(['refused:report_export', 'refused:report_export']);
})->group('RF-IN-06');

it('un fichero de fila presente o ausente no sube ninguna metrica', function (): void {
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping(
        new InMemoryGeneratedFileStore([], [], RecordedFileLocation::Missing, GeneratedFileRemoval::Absent),
        $metricas,
        new RecordingHousekeepingLogger,
        new PassThroughLedgerWrite,
    );

    expect($limpieza->locateRecorded(areaDePrueba(), '/srv/raiz/x/y'))->toBe(RecordedFileLocation::Missing)
        ->and($limpieza->discardRecorded(areaDePrueba(), '/srv/raiz/x/y'))->toBe(GeneratedFileRemoval::Absent)
        ->and($metricas->calls)->toBe([]);
})->group('RF-IN-06');

it('el fichero desaparecido sube su metrica y avisa sin uuid', function (): void {
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $logger = new RecordingHousekeepingLogger;
    $limpieza = new GeneratedFileHousekeeping(new InMemoryGeneratedFileStore([]), $metricas, $logger, new PassThroughLedgerWrite);

    $limpieza->fileMissing(GeneratedFileClass::DataExport);

    expect($metricas->calls)->toBe(['missing:data_export'])
        ->and($logger->records)->toBe([['warning', 'generated_files.file_missing', ['class' => 'data_export']]]);
})->group('RF-PD-14', 'RL-15');

it('cuenta y publica los ficheros que superan el plazo de aviso, sin tocarlos', function (): void {
    $almacen = new InMemoryGeneratedFileStore(['a' => 31 * 86400, 'b' => 30 * 86400, 'c' => 86400]);
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping($almacen, $metricas, new RecordingHousekeepingLogger, new PassThroughLedgerWrite);

    $vencidos = $limpieza->reportOverdue(areaDePrueba(GeneratedFileClass::LegalExportConsole), 30 * 86400, ahoraDeLimpieza());

    expect($vencidos)->toBe(1)
        ->and($almacen->removed)->toBe([])
        ->and($metricas->calls)->toBe(['overdue:legal_export_console=1'])
        ->and($limpieza->countOlderThan(areaDePrueba(), 86399, ahoraDeLimpieza()))->toBe(3);
})->group('RF-IN-05', 'RL-19');

it('un borrado que el sistema de ficheros niega sube su metrica y no cuenta como borrado', function (): void {
    $almacen = new InMemoryGeneratedFileStore(['sin-permiso' => 99_999], ['sin-permiso' => GeneratedFileRemoval::Failed]);
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping($almacen, $metricas, new RecordingHousekeepingLogger, new PassThroughLedgerWrite);

    expect($limpieza->sweepOrphans(areaDePrueba(), static fn (): int => 1, ahoraDeLimpieza()))->toBe(0)
        ->and($metricas->calls)->toBe(['remove_failed:report_export']);
})->group('RF-IN-06', 'RL-11');

it('el fichero de una fila que no se puede borrar sube su metrica', function (): void {
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $limpieza = new GeneratedFileHousekeeping(
        new InMemoryGeneratedFileStore([], [], RecordedFileLocation::Present, GeneratedFileRemoval::Failed),
        $metricas,
        new RecordingHousekeepingLogger,
        new PassThroughLedgerWrite,
    );

    expect($limpieza->discardRecorded(areaDePrueba(), '/srv/raiz/x/y'))->toBe(GeneratedFileRemoval::Failed)
        ->and($metricas->calls)->toBe(['remove_failed:report_export']);
})->group('RF-PD-14', 'RL-11');

it('sin raiz no hay conciliacion: lo dice en un aviso, sin ruta', function (): void {
    $almacen = new InMemoryGeneratedFileStore([]);
    $logger = new RecordingHousekeepingLogger;
    $limpieza = new GeneratedFileHousekeeping($almacen, new RecordingGeneratedFileMetricsDouble, $logger, new PassThroughLedgerWrite);

    expect($limpieza->isAvailable(areaDePrueba()))->toBeTrue()
        ->and($logger->records)->toBe([]);

    $almacen->rootAvailable = false;

    expect($limpieza->isAvailable(areaDePrueba()))->toBeFalse()
        ->and($logger->records)->toBe([['warning', 'generated_files.root_unavailable', ['class' => 'report_export']]]);
})->group('RF-PD-14', 'RL-15');

it('marca y publica el fichero desaparecido solo si fue esta llamada quien marco la fila', function (): void {
    $metricas = new RecordingGeneratedFileMetricsDouble;
    $candado = new PassThroughLedgerWrite;
    $limpieza = new GeneratedFileHousekeeping(new InMemoryGeneratedFileStore([]), $metricas, new RecordingHousekeepingLogger, $candado);
    $publicados = 0;
    $publicar = static function () use (&$publicados): void {
        $publicados++;
    };

    $primera = $limpieza->purgeMissing(GeneratedFileClass::DataExport, static fn (): bool => true, $publicar);
    // La segunda pasada encuentra la fila ya marcada: el UPDATE condicional no cambia nada.
    $segunda = $limpieza->purgeMissing(GeneratedFileClass::DataExport, static fn (): bool => false, $publicar);

    expect($primera)->toBeTrue()
        ->and($segunda)->toBeFalse()
        ->and($publicados)->toBe(1)
        ->and($metricas->calls)->toBe(['missing:data_export'])
        // Las dos bajo el candado de la cadena, que es lo que las serializa.
        ->and($candado->locks)->toBe(2);
})->group('RF-PD-14', 'RL-15');
