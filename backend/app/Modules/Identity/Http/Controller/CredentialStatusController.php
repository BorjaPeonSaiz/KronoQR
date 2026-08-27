<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\CredentialStatusBoard;
use App\Modules\Identity\Http\Request\IndexCredentialStatusRequest;
use App\Modules\Identity\Http\Resource\CredentialStatusResource;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/credentials/status` — el panel de RF-QR-08.
 *
 * *«RF-QR-08 existe para que RRHH vea de un vistazo quien no puede fichar
 * todavia. Sin el, el problema se descubre delante del quiosco a las 06:00»*
 * (doc 02 §5.5).
 *
 * **`handle()` y no `handleAndPublishMetrics()`.** El panel se consulta muchas
 * veces al dia y no tiene por que escribir en disco en cada peticion; quien
 * publica las dos metricas del §8.2 es el comando `credentials:status`, que el
 * planificador ejecuta cada hora. Si lo hiciera este endpoint, las series
 * dependerian de que alguien tuviera el panel abierto.
 *
 * **`no-store`**: es una lista nominal de la plantilla con su centro y su
 * departamento. No tiene por que quedarse en la cache de ningun proxy.
 */
final class CredentialStatusController extends Controller
{
    public function __invoke(
        IndexCredentialStatusRequest $request,
        CredentialStatusBoard $board,
    ): JsonResponse {
        return (new CredentialStatusResource($board->handle($request->toQuery())))
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
