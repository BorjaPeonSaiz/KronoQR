<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * Se ha pedido descargar una exportacion que todavia esta `pending` o `running`
 * (**RF-PD-14**).
 *
 * **`409` y no `404`**, y la diferencia le cambia por completo la accion a quien
 * la recibe: `404` dice «esto no existe, deja de intentarlo» y `409` dice
 * «espera unos segundos y vuelve». El panel sondea la lista cada cinco segundos
 * mientras dura la generacion, y con un `404` habria dejado de sondear.
 *
 * Una que **fallo** o cuyo fichero ya se **purgo** si es `404`: ahi no hay nada
 * que esperar. La fila sigue en la lista con su estado para que se sepa que
 * existio (regla dura 5).
 */
final class DataExportNotReady extends ProductDomainException
{
    public function __construct(public readonly string $uuid)
    {
        parent::__construct(\sprintf('The data export "%s" has not finished yet.', $uuid));
    }
}
