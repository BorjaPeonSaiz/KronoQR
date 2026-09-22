<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use App\Modules\Workforce\Application\Port\AbsenceView;
use App\Modules\Workforce\Application\Query\AbsenceQueries;
use App\Modules\Workforce\Application\UseCase\CorrectAbsenceHandler;
use App\Modules\Workforce\Application\UseCase\RegisterAbsenceHandler;
use App\Modules\Workforce\Application\UseCase\VoidAbsenceHandler;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Http\Request\CorrectAbsenceRequest;
use App\Modules\Workforce\Http\Request\IndexAbsenceRequest;
use App\Modules\Workforce\Http\Request\StoreAbsenceRequest;
use App\Modules\Workforce\Http\Request\VoidAbsenceRequest;
use App\Modules\Workforce\Http\Resource\AbsenceDetailResource;
use App\Modules\Workforce\Http\Resource\AbsenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Ausencias: listado, detalle con historial, alta, correccion y anulacion
 * (**RF-GP-04**, tarea 3.10).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, invoca el caso de
 * uso y serializa el `Resource` (doc 02 §3.5). **Ninguna decision vive aqui**:
 * el versionado lo hace {@see CorrectAbsenceHandler} dentro de su transaccion,
 * el asiento de `audit_log` lo escribe el listener de `Compliance` y las
 * invariantes son del modelo de dominio y del esquema.
 *
 * ## `DELETE` no existe
 *
 * Ni aqui ni en ninguna parte de esta API (regla dura 5). Quitar una ausencia es
 * `POST …/void`: un hecho con nombre, con autor, con motivo y con asiento.
 *
 * ## El alcance se aplica de dos formas distintas, y la diferencia es deliberada
 *
 * - En el **listado**, filtrando en la consulta. No hay `403`: un responsable ve
 *   las ausencias de su gente y no se entera de que existen mas. Un `403` al
 *   listar convertiria su pantalla en un error permanente.
 * - En el **detalle**, comprobando el recurso ya cargado y respondiendo `403`
 *   con asiento en `audit_log` (escenario «Aislamiento por departamento» del doc
 *   01 §11). Aqui si hay un sujeto identificable al que apuntar en el trail.
 *
 * Y **despues del `404`** a proposito: quien se equivoca de identificador tiene
 * que recibir «eso no existe» y no un asiento de intento fuera de alcance a
 * nombre de nadie.
 *
 * ## Las escrituras releen la ausencia antes de responder
 *
 * Los casos de uso devuelven el modelo de dominio, que habla de la persona por
 * su UUID; el contrato devuelve ademas su codigo, su nombre y su departamento.
 * La relectura resuelve esos tres y el `created_at` de la fila con una sola
 * consulta, en lugar de que el caso de uso arrastre datos de presentacion que no
 * le corresponden.
 */
final class AbsenceController extends Controller
{
    public function index(
        IndexAbsenceRequest $request,
        AbsenceQueries $queries,
        ScopeGuard $scope,
        InstallationSiteProvider $sites,
        Clock $clock,
    ): JsonResponse {
        // La zona del centro decide cual es «el mes en curso» (ADR-040, regla
        // dura 3): el servidor corre en UTC y el 1 de marzo a las 00:30 en
        // Canarias sigue siendo febrero en UTC. `UTC` de respaldo si todavia no
        // hay centro —solo antes de la puesta en marcha, cuando no hay ninguna
        // ausencia que listar—: un `409` aqui convertiria una pantalla vacia en
        // un error.
        $site = $sites->installationSite();

        $page = $queries->page(
            // La zona y el instante entran resueltos: el `FormRequest` compone el
            // periodo pero no lee ni el centro ni el reloj (regla dura 2).
            $request->toFilter(
                $scope,
                $site instanceof InstallationSite ? $site->timezone : 'UTC',
                $clock->now(),
            ),
            $request->page(),
            $request->perPage(),
        );

        $includesNote = Gate::allows('viewNote', Absence::class);

        return response()->json([
            'data' => array_map(
                static fn (AbsenceView $view): array => (new AbsenceResource($view, $includesNote))
                    ->toArray($request),
                $page['items'],
            ),
            'meta' => [
                'page' => $page['page'],
                'per_page' => $page['per_page'],
                'total' => $page['total'],
                'total_pages' => $page['total_pages'],
            ],
        ]);
    }

    public function show(
        Request $request,
        string $uuid,
        AbsenceQueries $queries,
        ScopeGuard $scope,
    ): JsonResponse {
        // Sin `FormRequest` porque no hay nada que validar, pero con policy: la
        // regla dura 18 no admite endpoints sin autorizacion comprobada.
        Gate::authorize('view', Absence::class);

        $view = $queries->find($uuid);

        if ($view === null) {
            throw new NotFoundHttpException;
        }

        $this->ensureWithinScope($request, $scope, $view);

        return (new AbsenceDetailResource(
            $view,
            $queries->historyOf($uuid),
            Gate::allows('viewNote', Absence::class),
        ))->response();
    }

    public function store(
        StoreAbsenceRequest $request,
        RegisterAbsenceHandler $handler,
        AbsenceQueries $queries,
    ): JsonResponse {
        $absence = $handler->handle($request->toCommand());

        if ($absence === null) {
            // La persona no existe. `422` y no `404`: el identificador va en el
            // cuerpo, asi que hay un campo que corregir en el formulario. El
            // `exists:` del `FormRequest` lo caza antes; esto cubre la carrera
            // entre la validacion y la escritura.
            throw new UnprocessableEntityHttpException('No existe ninguna persona con ese identificador.');
        }

        return $this->respondWith($request, $queries, $absence->uuid, JsonResponse::HTTP_CREATED);
    }

    public function update(
        CorrectAbsenceRequest $request,
        string $uuid,
        CorrectAbsenceHandler $handler,
        AbsenceQueries $queries,
    ): JsonResponse {
        $corrected = $handler->handle($request->toCommand($uuid));

        if ($corrected === null) {
            throw new NotFoundHttpException;
        }

        // El `uuid` de la RESPUESTA es el de la version nueva, no el de la ruta
        // (ADR-035): es el que hay que usar en la correccion siguiente.
        return $this->respondWith($request, $queries, $corrected->uuid, JsonResponse::HTTP_OK);
    }

    public function void(
        VoidAbsenceRequest $request,
        string $uuid,
        VoidAbsenceHandler $handler,
        AbsenceQueries $queries,
    ): JsonResponse {
        $voided = $handler->handle($request->toCommand($uuid));

        if ($voided === null) {
            throw new NotFoundHttpException;
        }

        // El mismo `uuid`: anular no crea version.
        return $this->respondWith($request, $queries, $voided->uuid, JsonResponse::HTTP_OK);
    }

    /**
     * Relee la ausencia y la serializa con el codigo, el nombre y el
     * departamento que el contrato exige.
     */
    private function respondWith(
        Request $request,
        AbsenceQueries $queries,
        string $uuid,
        int $status,
    ): JsonResponse {
        $view = $queries->find($uuid);

        if ($view === null) {
            // Se acaba de escribir dentro de una transaccion confirmada: si no
            // esta, algo va muy mal y callarlo seria peor.
            throw new NotFoundHttpException;
        }

        return (new AbsenceResource($view, Gate::allows('viewNote', Absence::class)))
            ->response()
            ->setStatusCode($status);
    }

    private function ensureWithinScope(Request $request, ScopeGuard $scope, AbsenceView $view): void
    {
        // El alcance se resuelve del actor de ESTA peticion, no de una propiedad
        // guardada: quitarle un departamento a un responsable tiene que notarse
        // en la llamada siguiente y no cuando caduque su sesion.
        $scope->ensureReaches(
            $scope->scopeOf($request->user()),
            $view->departmentId,
            'absence',
            $view->absence->employeeUuid,
        );
    }
}
