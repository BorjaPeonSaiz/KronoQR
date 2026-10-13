<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

/**
 * Dar por revisado un asiento `retention.purge_executed` no admisible (RL-04,
 * RS-07, ADR-057 enmienda del 10-10-2026 §6).
 */
final readonly class ReviewOutOfBoundsPurgeCommand
{
    public function __construct(
        public int $purgeAuditEntryId,
        /** Texto libre: va al asiento, nunca al log tecnico (regla dura 21). */
        public string $reason,
        /** Cuenta de gestion activa que responde de la revision; es el actor del asiento. */
        public int $responsibleUserId,
    ) {}
}
