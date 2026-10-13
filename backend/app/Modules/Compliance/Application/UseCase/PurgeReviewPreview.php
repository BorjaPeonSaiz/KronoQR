<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

/**
 * Lo que una revision de purga diria y explicaria, sin escribirla: la vista
 * previa de `compliance:reconcile-work-record --acknowledge` sin motivo o sin
 * responsable (ADR-057 enmienda del 10-10-2026 §6).
 */
final readonly class PurgeReviewPreview
{
    /**
     * @param  list<string>  $violations  Lo que el asiento incumple, por nombre de campo.
     */
    public function __construct(
        public int $purgeAuditEntryId,
        public array $violations,
        /** Si, una vez revisada, vuelve a explicar los borrados anteriores a su corte. */
        public bool $explainsDeletions,
        public ?string $cutoffDate,
        public ?int $siteId,
        /** El suelo legal efectivo con el que se juzgo el asiento. */
        public int $floorYears,
    ) {}
}
