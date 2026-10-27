<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsBusy;
use Closure;

/**
 * Un tope de Chromium sin Redis: apunta cada espera pedida y, si se crea
 * lleno, rechaza como lo haria el embudo sin huecos, sin ejecutar la
 * composicion.
 */
final class RecordingPdfRenderingSlots implements PdfRenderingSlots
{
    /** @var list<int> */
    public array $waits = [];

    public function __construct(private readonly bool $full = false) {}

    public function compose(Closure $render, int $waitSeconds): string
    {
        $this->waits[] = $waitSeconds;

        if ($this->full) {
            throw PdfRenderingSlotsBusy::allSlotsTaken($waitSeconds);
        }

        return $render();
    }
}
