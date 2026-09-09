<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\ListErrorEvents;
use App\Modules\Product\Application\UseCase\ResolveErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Http\Request\IndexErrorEventRequest;
use App\Modules\Product\Http\Resource\ErrorEventCollectionResource;
use App\Modules\Product\Http\Resource\ErrorEventResource;
use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/diagnostics/errors` y
 * `POST /api/v1/diagnostics/errors/{id}/resolve` — el historico de errores y su
 * resolucion (**RF-PD-15**, Anexo B).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest` —o el `Gate`, en el
 * segundo—, invoca el caso de uso y serializa el `Resource`. **Ninguna decision
 * vive aqui**: los filtros ya llegan tipados, el instante lo pone el puerto
 * `Clock`, la zona sale del centro y la idempotencia la garantiza el `WHERE` de
 * la sentencia.
 *
 * ## `POST` para resolver y `GET` para mirar
 *
 * Resolver escribe: cambia dos columnas de una fila. Que no deje asiento en
 * `audit_log` —son datos tecnicos sin relevancia legal, regla dura 6 al reves—
 * no lo convierte en una lectura.
 *
 * ## `404` y no `403` cuando el identificador no existe
 *
 * Aqui no hay nada que ocultar: quien llega hasta este metodo ya es `admin` de
 * la instalacion y el historico no describe a ninguna persona. Un `403`
 * generico, como el que si usa el fichaje (regla dura 17), solo confundiria a
 * quien esta depurando por que su enlace no funciona.
 *
 * ## Ninguna de las dos degrada con la licencia caducada (regla dura 15)
 *
 * Es justamente lo que se necesita cuando algo va mal, y ADR-019 es explicito:
 * la licencia recorta funcionalidades accesorias, nunca el diagnostico ni el
 * registro.
 */
final class ErrorEventController extends Controller
{
    public function index(IndexErrorEventRequest $request, ListErrorEvents $errors): JsonResponse
    {
        return (new ErrorEventCollectionResource(
            $errors->handle($request->toQuery()),
            // Un acceso de soporte no ve QUIEN dio un fallo por atendido: es la
            // unica columna del historico con un nombre de persona dentro, y el
            // paquete de diagnostico ya la excluia por lo mismo (decision 14).
            withResolver: ! self::isSupport($request),
        ))->response();
    }

    /**
     * El `{id}` llega como entero por el `whereNumber()` de la ruta, asi que
     * aqui no hay nada que validar: un identificador con letras es un `404` del
     * enrutador antes de llegar.
     */
    public function resolve(Request $request, int $id, ResolveErrorEvent $resolve): JsonResponse
    {
        /*
         * La autorizacion va aqui y no en un `FormRequest` porque esta peticion
         * **no tiene cuerpo**: un `FormRequest` sin `rules()` solo existiria
         * para llamar al `Gate`. La regla dura 18 pide policy y prueba negativa,
         * no un fichero.
         */
        Gate::authorize('resolve', ErrorEvent::class);

        $userId = self::actorUserId($request);

        if ($userId === null) {
            /*
             * Inalcanzable con la policy delante: llegar aqui sin una cuenta de
             * gestion seria una ruta mal montada. Fallar en voz alta es lo
             * correcto cuando el orden de los controles se ha roto, y un `404`
             * es lo que menos cuenta a quien no deberia estar aqui.
             */
            throw new NotFoundHttpException;
        }

        $event = $resolve->handle($id, $userId);

        if (! $event instanceof ErrorEvent) {
            throw new NotFoundHttpException;
        }

        // Quien llega hasta aqui nunca es un actor de soporte -la policy lo
        // rechaza-, asi que el autor sale entero. Se pasa explicito de todos
        // modos: el dia que la policy cambie, esta linea sigue diciendo lo que
        // se decidio.
        return (new ErrorEventResource($event, withResolver: ! self::isSupport($request)))->response();
    }

    /**
     * Si quien pregunta es un acceso temporal del fabricante (RF-PD-11).
     *
     * Se pregunta al puerto compartido y no por la clase: `Product` no puede
     * importar la cuenta de `Identity` (doc 02 §1.6).
     */
    private static function isSupport(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof ManagementActor && $actor->isSupportActor();
    }

    /**
     * La clave interna de la cuenta que resuelve, que es lo que guarda
     * `resolved_by_user_id`.
     *
     * Por `getAuthIdentifier()` y no por el modelo, igual que el resto del
     * modulo: `Product` no puede importar la cuenta de `Identity` (doc 02 §1.6).
     */
    private static function actorUserId(Request $request): ?int
    {
        $identifier = $request->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
