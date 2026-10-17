<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\LegalExportEmployeeMatch;

/**
 * Resultado de {@see SearchLegalExportEmployees}: como mucho
 * {@see SearchLegalExportEmployees::MAX_RESULTS} personas y si la lista se
 * recorto (RF-IN-05).
 */
final readonly class LegalExportEmployeeMatches
{
    /**
     * @param  list<LegalExportEmployeeMatch>  $matches
     */
    public function __construct(
        public array $matches,
        public bool $truncated,
    ) {}

    public function count(): int
    {
        return \count($this->matches);
    }
}
