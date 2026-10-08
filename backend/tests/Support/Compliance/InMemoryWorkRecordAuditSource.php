<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\WorkRecordAuditSource;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;

/**
 * El registro frente a su auditoria, en memoria: devuelve los pares que se le
 * dan y apunta con que ventana se le pregunto.
 */
final class InMemoryWorkRecordAuditSource implements WorkRecordAuditSource
{
    /** @var list<WorkRecordReconciliationWindow> */
    public array $askedWindows = [];

    /**
     * @param  list<WorkRecordPair>  $pairs
     */
    public function __construct(private readonly array $pairs = []) {}

    public function pairs(WorkRecordReconciliationWindow $window, int $chunkSize): iterable
    {
        $this->askedWindows[] = $window;

        yield from $this->pairs;
    }
}
