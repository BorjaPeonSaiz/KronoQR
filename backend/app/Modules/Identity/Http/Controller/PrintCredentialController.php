<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\PrintCredential;
use App\Modules\Identity\Http\Request\PrintCredentialRequest;
use App\Modules\Identity\Http\Response\PrintedCardsResponse;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/v1/credentials/{uuid}/print` — el PDF de una tarjeta (RF-QR-04).
 *
 * **Este endpoint acuña el QR** (ADR-034): a partir de su `200`, esa credencial
 * puede fichar. El controlador no sabe nada de eso —no conoce el HMAC, ni la
 * clave, ni Browsershot—: recibe un DTO, invoca el caso de uso y transmite el
 * documento. El orden de los seis pasos vive en `MintCards`.
 *
 * **El `409` no se traduce aqui.** `CredentialAlreadyPrinted` y
 * `CredentialAlreadyRevoked` las convierte el manejador global de
 * `bootstrap/app.php`, que es donde se traducen todas las excepciones de dominio
 * del producto.
 */
final class PrintCredentialController extends Controller
{
    public function __invoke(
        PrintCredentialRequest $request,
        string $uuid,
        PrintCredential $handler,
    ): Response {
        $actor = $request->user();

        $printed = $handler->handle($request->toCommand($uuid, $actor instanceof User ? $actor->id : null));

        if ($printed === null) {
            // La credencial no existe. `404` y no `422`: el recurso al que apunta
            // la URL no esta.
            throw new NotFoundHttpException;
        }

        return PrintedCardsResponse::of($printed);
    }
}
