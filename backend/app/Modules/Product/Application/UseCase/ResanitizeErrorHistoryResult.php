<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

/**
 * Lo que hizo {@see ResanitizeErrorHistory}: tres recuentos, ningun contenido.
 */
final readonly class ResanitizeErrorHistoryResult
{
    public function __construct(
        /** Grupos leidos. */
        public int $rows,
        /** Grupos reescritos en su sitio. */
        public int $rewritten,
        /** Grupos fundidos con otro que ya tenia su huella nueva. */
        public int $merged,
    ) {}
}
