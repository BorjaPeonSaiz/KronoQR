<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Http\Policy\ErrorEventPolicy;
use App\Modules\Product\Http\Request\ReportClientErrorsRequest;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/client-errors` — el panel y el portal vacian su buffer de
 * errores (**RF-PD-15**, decision 7 de la ficha 5.12).
 *
 * ## `202` y no `201`, y el cuerpo es solo un numero
 *
 * `accepted` dice **cuantos se han persistido, contando desde el primero**. El
 * cliente vacia de su buffer exactamente esos (`acknowledge(n)`) y conserva el
 * resto para el siguiente envio. Por eso el recuento es un prefijo y no un total
 * disperso: si hubiera huecos, el cliente descartaria errores que no llegaron a
 * guardarse.
 *
 * ## NUNCA devuelve el historico
 *
 * Ni un grupo, ni un recuento de la instalacion, ni el identificador de la fila
 * que se acaba de crear. Consultar es otra potestad —`diagnostics:*`, de
 * `admin`— y otra ruta. Que cualquier sesion de portal pueda **escribir** aqui
 * no puede convertirse en que pueda **leer** lo que escriben las demas.
 *
 * ## Que reportar un error no puede costar caro
 *
 * El sumidero no lanza nunca (regla dura 19): si la base de datos no responde,
 * `accepted` es `0`, el cliente conserva su buffer y nadie ve un `500` por
 * intentar contar que algo fallo.
 *
 * ## El quiosco no pasa por aqui
 *
 * Tiene su canal dentro del latido, y su token recibe `403` en esta ruta. Ver
 * {@see ErrorEventPolicy}.
 */
final class ClientErrorController extends Controller
{
    public function store(ReportClientErrorsRequest $request, ErrorEventSink $errors): JsonResponse
    {
        return new JsonResponse(
            ['accepted' => $errors->recordAll($request->toReports())],
            JsonResponse::HTTP_ACCEPTED,
        );
    }
}
