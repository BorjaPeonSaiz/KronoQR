<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Exception\AuditPartitionCreationUnavailable;
use App\Modules\Compliance\Application\Port\AuditLogPartitions;

/**
 * Particiones en memoria, para probar el calendario de la tarea programada
 * —noviembre, año en curso, año siguiente— sin tocar PostgreSQL ni esperar a
 * que llegue diciembre.
 *
 * Los años de `$unavailable` imitan una base sin la funcion de ADR-042: su
 * `create()` lanza {@see AuditPartitionCreationUnavailable} y no crea nada.
 */
final class InMemoryAuditLogPartitions implements AuditLogPartitions
{
    /** @var list<int> */
    public array $created = [];

    /**
     * @param  list<int>  $years
     * @param  list<int>  $unavailable
     */
    public function __construct(private array $years = [], private readonly array $unavailable = []) {}

    public function years(): array
    {
        sort($this->years);

        return $this->years;
    }

    public function create(int $year): void
    {
        if (\in_array($year, $this->unavailable, true)) {
            throw AuditPartitionCreationUnavailable::functionMissing($year);
        }

        $this->created[] = $year;
        $this->years[] = $year;
    }
}
