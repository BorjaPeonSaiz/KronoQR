<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\WorkRecordReconciliationMetrics;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use DateTimeImmutable;

/**
 * Lo que la conciliacion publicaria, en memoria.
 */
final class RecordingWorkRecordReconciliationMetrics implements WorkRecordReconciliationMetrics
{
    /** @var list<array{result: WorkRecordReconciliationResult, at: DateTimeImmutable}> */
    public array $recorded = [];

    public function record(WorkRecordReconciliationResult $result, DateTimeImmutable $at): void
    {
        $this->recorded[] = ['result' => $result, 'at' => $at];
    }
}
