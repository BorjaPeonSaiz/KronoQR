<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

use App\Modules\Product\Domain\ValueObject\DataExportOrigin;

/**
 * Pedir la exportacion integra de todos los datos (**RF-PD-14**, RL-20).
 *
 * ## Dos campos y ninguno es una opcion
 *
 * No hay periodo, ni filtro por empleado, ni «solo estas tablas». **Es
 * deliberado y es el requisito**: RL-20 promete que el cliente se puede llevar
 * *todos* sus datos, y una exportacion con opciones seria una exportacion que se
 * puede pedir mal — alguien marcaria tres casillas, se llevaria media copia y
 * creeria tener el respaldo completo justo el dia en que deja de tener el
 * producto.
 *
 * Quien necesita un corte por periodo o por persona tiene la exportacion legal
 * (RF-IN-05) y el informe por periodo (RF-IN-04), que existen para eso.
 */
final readonly class RequestDataExportCommand
{
    public function __construct(
        public DataExportOrigin $requestedVia,
        /** Nulo desde la consola: ahi no hay sesion que atribuir. */
        public ?int $requestedByUserId,
    ) {}
}
