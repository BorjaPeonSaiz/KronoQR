<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;

/**
 * Doble del lector de la cadena para la suite Unit.
 *
 * Escrito a mano y no con `Mockery`: lo que hace falta es un almacen que se
 * pueda preparar con una cadena concreta —intacta, alterada o con un ancla
 * delante—, no una expectativa de llamada.
 */
final class InMemoryAuditChainReader implements AuditChainReader
{
    /**
     * @param  list<AuditEntry>  $entries
     * @param  list<AuditChainAnchor>  $anchors
     */
    public function __construct(
        private readonly array $entries = [],
        private readonly array $anchors = [],
    ) {}

    public function inChainOrder(int $chunkSize = 1000): iterable
    {
        yield from $this->entries;
    }

    public function anchorSealedWith(string $hash): ?AuditChainAnchor
    {
        foreach ($this->anchors as $anchor) {
            if ($anchor->lastHash === $hash) {
                return $anchor;
            }
        }

        return null;
    }
}
