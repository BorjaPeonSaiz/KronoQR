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
     * @param  list<CredentialStatusRow>  $rows  Las filas servidas: la pagina, si se pidio una.
     */
    public function __construct(
        public array $rows,
        public SiteCredentialCoverage $coverage,
        /** Cuantas filas pasan los filtros, en todas las paginas (2.3.0). */
        public int $total = 0,
        public int $page = 1,
        public int $perPage = 1,
    ) {}

    /** Cero sin filas: no hay ninguna pagina que enseñar. */
    public function totalPages(): int
    {
        return (int) ceil($this->total / max(1, $this->perPage));
    }

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
