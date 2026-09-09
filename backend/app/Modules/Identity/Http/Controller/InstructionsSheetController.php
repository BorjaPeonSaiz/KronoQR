<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\RenderInstructionsSheet;
use App\Modules\Identity\Http\Request\InstructionsSheetRequest;
use App\Modules\Identity\Http\Response\InstructionsSheetResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/v1/credentials/instructions-sheet` — la hoja de instrucciones que se
 * entrega junto con la tarjeta (tarea 5.11b, **RL-05**).
 *
 * **Es el unico documento del modulo que se pide con `GET`**, y la diferencia
 * con `print` es exactamente la que justifica el verbo: imprimir una tarjeta
 * **acuña** su QR y es irreversible (ADR-034), mientras que esta hoja no cambia
 * el estado de nada, no lleva ningun secreto y es la misma para toda la
 * plantilla. Que un navegador la repita al recargar no tiene consecuencia
 * ninguna.
 *
 * El controlador no sabe componer un PDF, no conoce el idioma de la instalacion
 * ni sabe donde vive el portal: recibe un `FormRequest`, invoca el caso de uso y
 * transmite el documento.
 */
final class InstructionsSheetController extends Controller
{
    public function __invoke(
        InstructionsSheetRequest $request,
        RenderInstructionsSheet $handler,
    ): Response {
        return InstructionsSheetResponse::of($handler->handle($request->requestedLocale()));
    }
}
