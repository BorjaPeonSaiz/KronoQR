<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

/**
 * Una pagina del listado de cuentas de gestion con su metadato de paginacion
 * (RF-ID-10, `PageMeta` del contrato).
 */
final readonly class ManagementAccountListing
{
    /**
     * @param  list<ManagementAccountView>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public int $totalPages,
    ) {}
}
