<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Una pagina de {@see SupportGrantUse}, con el total de la concesion (esquema
 * `PageMeta` del contrato).
 */
final readonly class SupportGrantUsePage
{
    /**
     * @param  list<SupportGrantUse>  $rows
     * @param  int  $total  Ventanas de uso de la concesion, en todas las paginas.
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        return $this->perPage < 1 ? 0 : (int) ceil($this->total / $this->perPage);
    }
}
