<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\PrintCredentialBatch;
use App\Modules\Identity\Http\Request\PrintCredentialBatchRequest;
use App\Modules\Identity\Http\Response\PrintedCardsResponse;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/credentials/print-batch` — la hoja A4 de un centro (RF-QR-04).
 *
 * **Un solo documento con N tarjetas**, no N documentos: una llamada cubre los
 * sesenta empleados de un centro.
 *
 * **No hay `404`.** Un centro sin nada pendiente devuelve `204`, que es la forma
 * que toma la idempotencia del lote (ADR-034): la segunda llamada no encuentra
 * nada. Que el centro exista lo comprueba la validacion del `FormRequest`, no
 * este metodo.
 */
final class PrintCredentialBatchController extends Controller
{
    public function __invoke(
        PrintCredentialBatchRequest $request,
        PrintCredentialBatch $handler,
    ): Response {
        $actor = $request->user();

        $printed = $handler->handle($request->toCommand($actor instanceof User ? $actor->id : null));

        return PrintedCardsResponse::of($printed);
    }
}
