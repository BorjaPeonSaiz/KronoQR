<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\WorkRecordAuditSource;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordAuditContext;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;

/**
 * El registro frente a su auditoria, en memoria: entrega primero el contexto de
 * purgas que se le da, despues los pares, y apunta con que ventana se le
 * pregunto.
 */
final class InMemoryWorkRecordAuditSource implements WorkRecordAuditSource
{
    /** @var list<WorkRecordReconciliationWindow> */
    public array $askedWindows = [];

    /**
     * @param  list<WorkRecordPair>  $pairs
     */
    public function __construct(
        private readonly array $pairs = [],
        private readonly WorkRecordAuditContext $context = new WorkRecordAuditContext,
    ) {}

    public function read(WorkRecordReconciliationWindow $window, int $chunkSize): iterable
    {
        $this->askedWindows[] = $window;

        yield $this->context;

        yield from $this->pairs;
    }
}
