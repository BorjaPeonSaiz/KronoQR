<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\UseCase\DownloadDataExportHandler;
use App\Modules\Product\Application\UseCase\ListDataExportsHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Domain\Exception\DataExportAlreadyInProgress;
use App\Modules\Product\Domain\Exception\DataExportNotReady;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Http\Resource\DataExportCollectionResource;
use App\Modules\Product\Http\Resource\DataExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las tres rutas de `/api/v1/data-export` (Anexo B del doc 01, **RF-PD-14**,
 * RL-20).
 *
 * Delgado como el resto: autoriza, invoca el caso de uso y serializa. **Ninguna
 * decision vive aqui.** La exclusion mutua la resuelve un indice unico, el
 * asiento lo escribe el listener de `Compliance`, el contenido lo decide el
 * catalogo del dominio y el aviso previo lo pinta el panel.
 *
 * ## Lo unico que el controlador si decide
 *
 * **Si el fichero sigue en el disco.** El caso de uso no abre ficheros —es la
 * capa que no toca el sistema de operativo—, asi que la comprobacion se hace
 * aqui y entra como argumento. Existe porque una fila `completed` cuyo fichero
 * alguien borro a mano para hacer sitio tiene que responder `404` y no reventar.
 *
 * ## Las tres nunca degradan con la licencia
 *
 * Regla dura 15 y ADR-019, y aqui con mas motivo que en ninguna otra ruta: RL-20
 * es la garantia de que el cliente puede llevarse sus datos **aunque la relacion
 * comercial termine**. Bloquearla por licencia caducada dejaria al cliente sin
 * acceso a informacion que esta obligado a conservar cuatro años, por una accion
 * del fabricante.
 */
final class DataExportController extends Controller
{
    public function index(ListDataExportsHandler $exports): JsonResponse
    {
        // El sujeto es el modelo de dominio y no una fila: la policy no autoriza
        // sobre una exportacion concreta —todas son iguales ante ella— sino
        // sobre «llevarse todos los datos de esta instalacion».
        Gate::authorize('viewAny', DataExport::class);

        return (new DataExportCollectionResource($exports->handle()))->response();
    }

    public function store(Request $request, RequestDataExportHandler $exports): JsonResponse
    {
        Gate::authorize('request', DataExport::class);

        try {
            $export = $exports->handle(new RequestDataExportCommand(
                requestedVia: DataExportOrigin::Panel,
                requestedByUserId: self::actorUserId($request),
            ));
        } catch (DataExportAlreadyInProgress $conflict) {
            return $this->inProgress($request, $conflict);
        }

        return (new DataExportResource($export))
            ->response()
            // `202` y no `201`: lo que existe al responder es la PETICION, no el
            // fichero. El panel sondea la lista hasta verla `completed`.
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function download(
        string $uuid,
        Request $request,
        DownloadDataExportHandler $exports,
    ): BinaryFileResponse|JsonResponse {
        Gate::authorize('download', DataExport::class);

        try {
            $export = $exports->handle(
                uuid: $uuid,
                // El caso de uso no toca el sistema de ficheros; esta es la
                // mitad que si puede. Existe porque una fila `completed` cuyo
                // fichero alguien borro a mano para hacer sitio tiene que
                // responder `404` en lugar de reventar.
                fileExists: static fn (string $path): bool => $path !== '' && is_file($path),
                downloadedByUserId: self::actorUserId($request),
            );
        } catch (DataExportNotReady) {
            return ProblemDetails::dataExportNotReady();
        }

        if ($export === null || $export->filePath === null) {
            // Sin detalle: enumerar por que no esta —no existe, fallo, se purgo—
            // no le cambia la accion a quien lo recibe, que ya ve el estado real
            // en la lista.
            throw new NotFoundHttpException;
        }

        /*
         * El asiento YA esta escrito cuando se llega aqui (RS-05). Ver
         * `DownloadDataExportHandler`: si se escribiera despues, una descarga
         * cortada a la mitad sacaria el fichero del servidor sin dejar rastro.
         */
        $response = response()->download($export->filePath, $export->fileName ?? 'kronoqr-export.zip', [
            'Content-Type' => 'application/zip',
            // Las dos cabeceras que permiten comprobar que llego entero sin
            // abrirlo: la huella tal como consta en la fila y en `audit_log`, y
            // el total de filas de datos del ZIP.
            'X-Kronoqr-Export-Sha256' => $export->sha256 ?? '',
            'X-Kronoqr-Export-Rows' => (string) array_sum($export->rowCounts),
        ]);

        /*
         * `Cache-Control` DESPUES de construir la respuesta, y no entre las
         * cabeceras de arriba.
         *
         * `BinaryFileResponse` reescribe su propio control de cache al
         * prepararse y deja `public`, que sobre el fichero con todos los datos
         * personales de la plantilla es exactamente lo contrario de lo que hace
         * falta: cualquier proxy del hotel podria quedarse una copia. Puesto
         * aqui, es el ultimo valor y manda.
         */
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * El `409` con la exportacion en curso dentro del cuerpo.
     *
     * La trae la propia excepcion: componerla aqui con una consulta propia
     * abriria un segundo camino de lectura de la tabla desde el borde, y ademas
     * podria devolver otra fila distinta de la que provoco el choque.
     */
    private function inProgress(Request $request, DataExportAlreadyInProgress $conflict): JsonResponse
    {
        if ($conflict->current === null) {
            // La que ocupaba el turno termino entre el choque contra el indice y
            // la relectura. El `409` sigue siendo correcto —esta peticion no
            // llego a crearse— y lo unico util que se puede decir es que se
            // reintente.
            return ProblemDetails::dataExportInProgress(
                'Habia otra exportacion en curso al pedir esta. Vuelve a intentarlo.',
                [],
            );
        }

        return ProblemDetails::dataExportInProgress(
            'Espera a que termine la exportacion pedida a las '
            .$conflict->current->requestedAt->format('H:i').' UTC. '
            .'Podras descargarla desde esta misma pantalla.',
            DataExportResource::payload($conflict->current),
        );
    }

    private static function actorUserId(Request $request): ?int
    {
        $identifier = $request->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
