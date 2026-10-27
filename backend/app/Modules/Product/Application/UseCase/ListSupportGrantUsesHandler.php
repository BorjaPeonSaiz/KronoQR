<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\Port\SupportGrantUsePage;
use App\Modules\Product\Application\Port\SupportGrantUses;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * Las ventanas de uso de una concesion de soporte (**RF-PD-11**, RL-15, RL-18,
 * ADR-020 §3).
 *
 * ## Que promesa cumple
 *
 * El doc 05 §6.3 le dice al hotel que cada sesion de trabajo del soporte queda
 * registrada y que la ve **en su panel**, con una linea por cada 15 minutos.
 * `GET /api/v1/support/grants` solo da el ultimo uso (`accessed_at`); las lineas
 * eran asientos de `audit_log` que solo salian en la exportacion integra.
 *
 * ## Mejor esfuerzo, y por que
 *
 * Los asientos los escribe {@see RecordSupportAccessUseHandler} despues de servir
 * la peticion, y su fallo no la tumba. Una ventana cuyo asiento fallo no esta
 * aqui. Lo dice el contrato.
 *
 * ## Sin asiento propio
 *
 * Leer el rastro de un acceso del fabricante no es acceder a datos de personas:
 * cada linea es un instante, un metodo y un patron de ruta, y quien pregunta es
 * el administrador que concedio o puede revocar ese acceso.
 */
final readonly class ListSupportGrantUsesHandler
{
    public function __construct(
        private SupportGrantRepository $grants,
        private SupportGrantUses $uses,
    ) {}

    /**
     * La pagina pedida, o `null` si el UUID no es de ninguna concesion.
     */
    public function handle(string $grantUuid, int $page, int $perPage): ?SupportGrantUsePage
    {
        return UseCaseInstrumentation::run(
            'product.list_support_grant_uses',
            fn (): ?SupportGrantUsePage => $this->doHandle($grantUuid, $page, $perPage),
        );
    }

    private function doHandle(string $grantUuid, int $page, int $perPage): ?SupportGrantUsePage
    {
        $grant = $this->grants->findByUuid($grantUuid);

        if ($grant === null) {
            return null;
        }

        return $this->uses->page($grant->persistedId(), $page, $perPage);
    }
}
