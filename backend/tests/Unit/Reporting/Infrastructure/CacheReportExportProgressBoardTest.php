<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;
use App\Modules\Reporting\Infrastructure\Adapter\CacheReportExportProgressBoard;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\Observability\RecordingLogger;

/*
 * El avance de las exportaciones en curso vive en la cache y **nunca rompe**
 * (V5-PA-4): una cache caida no convierte una generacion sana en fallida ni una
 * lista en un `503`. Y lo que se deja en el log es el `uuid` y la clase del
 * fallo, nunca su mensaje (regla dura 21).
 */

const CACHE_REPORT_EXPORT_PROGRESS_UUID = '019a12b4-5c6d-7e8f-9012-3456789abcde';

/** Una cache que falla en todo, como Redis caido con el circuito cerrado. */
function tableroDeAvanceCacheCaida(): Repository
{
    return new class(new ArrayStore) extends Repository
    {
        /**
         * @param  array<array-key, mixed>|string  $key
         */
        public function put($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('redis://secreto@cache: Connection refused');
        }

        /**
         * @param  array<array-key, mixed>|string  $key
         */
        public function get($key, $default = null): mixed
        {
            throw new RuntimeException('redis://secreto@cache: Connection refused');
        }

        /**
         * @param  array<array-key, mixed>|string  $key
         */
        public function forget($key): bool
        {
            throw new RuntimeException('redis://secreto@cache: Connection refused');
        }
    };
}

it('guarda, lee y olvida el avance de cada exportacion', function (): void {
    $board = new CacheReportExportProgressBoard(new Repository(new ArrayStore), new RecordingLogger, 3600);
    $at = new DateTimeImmutable('2026-03-08T09:00:05+00:00');

    $board->record(CACHE_REPORT_EXPORT_PROGRESS_UUID, ReportExportProgress::ofRows(ReportExportStage::Writing, 40, 100, $at));

    $leido = $board->of([CACHE_REPORT_EXPORT_PROGRESS_UUID, '019a12b4-0000-7000-8000-000000000000']);

    expect(array_keys($leido))->toBe([CACHE_REPORT_EXPORT_PROGRESS_UUID])
        ->and($leido[CACHE_REPORT_EXPORT_PROGRESS_UUID]->percent)->toBe(40)
        ->and($leido[CACHE_REPORT_EXPORT_PROGRESS_UUID]->stage)->toBe(ReportExportStage::Writing);

    $board->forget(CACHE_REPORT_EXPORT_PROGRESS_UUID);

    expect($board->of([CACHE_REPORT_EXPORT_PROGRESS_UUID]))->toBe([]);
})->group('RF-IN-06');

it('con la cache caida no rompe nada y deja en el log solo el uuid y la clase', function (): void {
    $log = new RecordingLogger;
    $board = new CacheReportExportProgressBoard(tableroDeAvanceCacheCaida(), $log, 3600);

    $board->record(CACHE_REPORT_EXPORT_PROGRESS_UUID, ReportExportProgress::at(ReportExportStage::Reading, new DateTimeImmutable('2026-03-08T09:00:05+00:00')));
    $board->forget(CACHE_REPORT_EXPORT_PROGRESS_UUID);
    $leido = $board->of([CACHE_REPORT_EXPORT_PROGRESS_UUID, '019a12b4-0000-7000-8000-000000000000']);

    expect($leido)->toBe([])
        // Si la cache no contesta para una, no se le pregunta por la siguiente.
        ->and($log->lines)->toHaveCount(3)
        ->and(array_column($log->lines, 'message'))->toBe(array_fill(0, 3, 'reporting.report_export_progress_unavailable'))
        ->and($log->first()['context'])->toBe([
            'operation' => 'record',
            'report_export_uuid' => CACHE_REPORT_EXPORT_PROGRESS_UUID,
            'failure' => RuntimeException::class,
        ])
        ->and(json_encode($log->lines, JSON_THROW_ON_ERROR))->not->toContain('secreto');
})->group('RF-IN-06');

it('lee como ninguno un avance que no reconoce', function (): void {
    $cache = new Repository(new ArrayStore);
    $cache->put('reporting:report-export-progress:'.CACHE_REPORT_EXPORT_PROGRESS_UUID, 'no es un avance', 60);

    $board = new CacheReportExportProgressBoard($cache, new RecordingLogger, 3600);

    expect($board->of([CACHE_REPORT_EXPORT_PROGRESS_UUID]))->toBe([]);
})->group('RF-IN-06');
