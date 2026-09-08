<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\TelemetryStateStore;
use App\Modules\Product\Domain\ValueObject\TelemetryState;

/**
 * El estado de la telemetria en memoria (RF-PD-12).
 *
 * Cuenta las llamadas por lo mismo que los otros dobles: con la telemetria
 * apagada tampoco se acuña identidad ni se escribe un fichero en el disco del
 * cliente. `$establishes` y `$saved` distinguen ademas **mirar** de **fijar**:
 * `product:telemetry` sin `--send` no puede tocar ninguno de los dos.
 */
final class InMemoryTelemetryStateStore implements TelemetryStateStore
{
    public int $establishes = 0;

    public int $reads = 0;

    /** @var list<TelemetryState> */
    public array $saved = [];

    public function __construct(private ?TelemetryState $state = new TelemetryState('11111111-2222-4333-8444-555555555555')) {}

    public function establish(): TelemetryState
    {
        $this->establishes++;

        if (! $this->state instanceof TelemetryState) {
            $this->save($this->provisional());
        }

        /** @var TelemetryState $state */
        $state = $this->state;

        return $state;
    }

    public function stored(): ?TelemetryState
    {
        $this->reads++;

        return $this->state;
    }

    public function provisional(): TelemetryState
    {
        return new TelemetryState('99999999-8888-4777-8666-555555555555');
    }

    public function save(TelemetryState $state): void
    {
        $this->saved[] = $state;
        $this->state = $state;
    }
}
