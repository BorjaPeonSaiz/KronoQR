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
     * Los rangos que se le han pedido, en orden: `['2026-03-01', '2026-03-31']`.
     *
     * El cuadro de impacto (RF-IN-08, tarea 3.13) pregunta **dos veces** —el periodo
     * y el anterior—, y que los dos rangos sean los correctos es lo que no puede
     * fallar sin que nadie lo note: una comparacion contra el mes equivocado es un
     * numero perfectamente creible.
     *
     * @var list<array{0: string, 1: string}>
     */
    public array $rangesAskedFor = [];

    /**
     * @param  array<int, array{complete: int, total: int}>  $completion
     * @param  array<string, array<int, array{complete: int, total: int}>>  $completionByRange  Indexado
     *                                                                                          por `from|to`, para que una prueba pueda dar cifras
     *                                                                                          distintas al periodo y al anterior. Lo que no este
     *                                                                                          aqui cae a `$completion`.
     */
    public function __construct(
        private readonly array $completion = [],
        private readonly array $completionByRange = [],
    ) {}

    public function completionOn(string $workDate): array
    {
        $this->askedFor = $workDate;

        return $this->completion;
    }

    public function completionBetween(string $from, string $to): array
    {
        $this->rangesAskedFor[] = [$from, $to];

        return $this->completionByRange[$from.'|'.$to] ?? $this->completion;
    }
}
