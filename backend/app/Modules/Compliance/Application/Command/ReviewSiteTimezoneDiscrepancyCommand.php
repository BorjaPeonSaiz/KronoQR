<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use App\Modules\Compliance\Domain\ValueObject\SiteTimezoneReviewTarget;

/**
 * Dar por revisada una discrepancia de la zona del centro (RN-23, ADR-056).
 */
final readonly class ReviewSiteTimezoneDiscrepancyCommand
{
    public function __construct(
        public SiteTimezoneReviewTarget $target,
        /** Texto libre: va al asiento, nunca al log tecnico (regla dura 21). */
        public string $reason,
        /** Cuenta de gestion activa (admin o rrhh) que responde; es el actor del asiento. */
        public int $responsibleUserId,
    ) {}
}
