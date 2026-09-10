<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;

/**
 * Doble de la lectura de jornadas completas que **recuerda que fecha se le
 * pidio** (RF-IN-08, doc 02 §8.2).
 *
 * La fecha es lo unico que decide `PublishAdoptionMetrics`, y es lo que no puede
 * fallar sin que nadie lo note: un ratio calculado sobre el dia equivocado es un
 * numero perfectamente creible. Por eso el doble la guarda en lugar de
 * limitarse a devolver un recuento fijo.
 */
final class RecordingWorkDayCompletionReader implements WorkDayCompletionReader
{
    public ?string $askedFor = null;

    /**
     * @param  array<int, array{complete: int, total: int}>  $completion
     */
    public function __construct(private readonly array $completion = []) {}

    public function completionOn(string $workDate): array
    {
        $this->askedFor = $workDate;

        return $this->completion;
    }
}
