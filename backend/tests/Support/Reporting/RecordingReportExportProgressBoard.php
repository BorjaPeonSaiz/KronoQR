<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;

/**
 * Un tablero de avance que **apunta todo lo que le cuentan**, en orden, para
 * poder afirmar por que fases paso una generacion (V5-PA-4).
 *
 * Guarda tambien el ultimo avance por exportacion, como el de verdad, para
 * que la lista lo pueda leer.
 */
final class RecordingReportExportProgressBoard implements ReportExportProgressBoard
{
    /** @var list<array{uuid: string, stage: string, percent: int|null}> */
    public array $recorded = [];

    /** @var list<string> */
    public array $forgotten = [];

    /** @var array<string, ReportExportProgress> */
    private array $latest = [];

    public function record(string $uuid, ReportExportProgress $progress): void
    {
        $this->recorded[] = ['uuid' => $uuid, 'stage' => $progress->stage->value, 'percent' => $progress->percent];
        $this->latest[$uuid] = $progress;
    }

    public function of(array $uuids): array
    {
        return array_intersect_key($this->latest, array_flip($uuids));
    }

    public function forget(string $uuid): void
    {
        $this->forgotten[] = $uuid;
        unset($this->latest[$uuid]);
    }

    /**
     * Las fases por las que paso, sin repetir las seguidas.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        $stages = [];

        foreach ($this->recorded as $entry) {
            if ($stages === [] || $stages[\count($stages) - 1] !== $entry['stage']) {
                $stages[] = $entry['stage'];
            }
        }

        return $stages;
    }
}
