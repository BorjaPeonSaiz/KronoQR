<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Domain\Model\DataExport;

/**
 * Las exportaciones integras recientes (**RF-PD-14**, RL-20).
 *
 * ## Veinte, sin paginacion
 *
 * Una instalacion pide una exportacion completa unas cuantas veces al año: antes
 * de una renovacion, ante un requerimiento, cuando cambia de proveedor. Veinte
 * cubre de sobra lo que alguien mira, y una barra de paginas sobre una lista que
 * cabe en media pantalla es complejidad sin nadie que la use. El historico
 * completo esta en `audit_log`, que si tiene su propia consulta.
 *
 * ## Las purgadas y las fallidas siguen en la lista
 *
 * Regla dura 5. Una lista que enseñara solo las descargables convertiria «tu
 * copia caduco hace tres dias» en «aqui no ha pasado nada», que es la peor
 * respuesta posible para quien viene a buscar su respaldo.
 */
final readonly class ListDataExportsHandler
{
    /** Lo que promete el esquema `DataExportCollection` con su `maxItems`. */
    public const int LIMIT = 20;

    public function __construct(private DataExportRepository $exports) {}

    /**
     * @return list<DataExport>
     */
    public function handle(): array
    {
        return $this->exports->recent(self::LIMIT);
    }
}
