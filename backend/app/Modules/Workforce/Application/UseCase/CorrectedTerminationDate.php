<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use DateTimeImmutable;

/**
 * La correccion de la fecha de cese ya guardada (RN-27): la fila de
 * `employment_period_corrections`, sin el motivo.
 */
final readonly class CorrectedTerminationDate
{
    public function __construct(
        public int $id,
        public string $periodUuid,
        public string $previousTerminatedOn,
        public string $terminatedOn,
        public DateTimeImmutable $correctedAt,
    ) {}
}
