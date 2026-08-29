<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\ValueObject\CredentialLifecycleStatus;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;

/**
 * Lo que devuelve {@see CredentialStatusBoard}: las filas y **un** recuento
 * (ADR-040). El recuento no se deriva de las filas aqui —`pending` ya las ha
 * podido acotar—: llega calculado del caso de uso.
 */
final readonly class CredentialStatusReport
{
    /**
     * @param  list<CredentialStatusRow>  $rows
     */
    public function __construct(
        public array $rows,
        public SiteCredentialCoverage $coverage,
    ) {}

    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = [];

        foreach (CredentialLifecycleStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($this->rows as $row) {
            $counts[$row->status->value]++;
        }

        return $counts;
    }

    public function employeesWithoutDeliveredCredential(): int
    {
        return $this->coverage->withoutDeliveredCredential;
    }

    public function pendingPrint(): int
    {
        return $this->coverage->pendingPrint;
    }
}
