<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * Una pagina del listado de cuentas de gestion (RF-ID-10).
 */
final readonly class ManagementAccountPage
{
    /**
     * @param  list<ManagementAccountRecord>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function totalPages(): int
    {
        return $this->total === 0 ? 0 : (int) ceil($this->total / max(1, $this->perPage));
    }
}
