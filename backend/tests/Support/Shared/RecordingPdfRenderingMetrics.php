<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\PdfRenderingMetrics;

/** Las metricas del tope de PDF, en memoria: cada rechazo con su motivo. */
final class RecordingPdfRenderingMetrics implements PdfRenderingMetrics
{
    /** @var list<string> */
    public array $rejections = [];

    public function rejected(string $reason): void
    {
        $this->rejections[] = $reason;
    }
}
