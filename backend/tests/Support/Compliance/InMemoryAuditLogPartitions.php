<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\AuditLogPartitions;

/**
 * Particiones en memoria, para probar el calendario de la tarea programada
 * —noviembre, año en curso, año siguiente— sin tocar PostgreSQL ni esperar a
 * que llegue diciembre.
 */
final class InMemoryAuditLogPartitions implements AuditLogPartitions
{
    /** @var list<int> */
    public array $created = [];

    /**
     * @param  list<int>  $years
     */
    public function __construct(private array $years = []) {}

    public function years(): array
    {
        sort($this->years);

        return $this->years;
    }

    public function create(int $year): void
    {
        $this->created[] = $year;
        $this->years[] = $year;
    }
}
