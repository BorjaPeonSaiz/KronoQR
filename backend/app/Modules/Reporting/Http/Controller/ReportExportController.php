<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Command\RequestReportExportCommand;
use App\Modules\Reporting\Application\UseCase\ListReportExports;
use App\Modules\Reporting\Application\UseCase\RequestReportExport;
use App\Modules\Reporting\Application\UseCase\ShowReportExport;
use App\Modules\Reporting\Domain\Exception\ReportExportAlreadyInProgress;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Http\Request\RequestReportExportRequest;
use App\Modules\Reporting\Http\Resource\ReportExportCollectionResource;
use App\Modules\Reporting\Http\Resource\ReportExportResource;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\Exception\FeatureNotLicensed;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las tres rutas de gestion de `/api/v1/reports/exports` (**RF-IN-06**,
 * **RF-IN-07**, ficha 3.9).
 *
 * Delgado como el resto: autoriza, invoca el caso de uso y serializa. **Ninguna
 * decision de negocio vive aqui.** La exclusion mutua la resuelve un indice
 * unico, el asiento lo escribe el listener de `Compliance`, el enlace lo acuña el
 * caso de uso y el alcance lo resuelve `ScopeGuard`.
 *
 * ## Lo que el controlador si decide: la licencia
 *
 * Y la decide **al pedir**, no al generar (decision 6, ADR-023):
 * `Feature::AdvancedReports` para `kind: period` —la misma que protege
 * `GET /reports/period` y su descarga, porque lo que sale es lo mismo— y
 * `Feature::PayrollExport` para `kind: payroll`, que estrena consumidor con esta
 * tarea.
 *
 * Un trabajo ya encolado **termina** aunque la licencia caduque entre medias: un
 * fichero a medias no le sirve a nadie, y borrarlo por una fecha del fabricante
 * seria castigar a quien ya habia pedido el informe. La exportacion para la
 * Inspeccion no se toca y no se degrada jamas (RL-06, regla dura 15).
 *
 * ## `404` y no `403` para la exportacion ajena
 *
 * Decision 2. Solo el solicitante ve y descarga la suya, tambien si quien
 * pregunta es `admin`. El filtro entra **en la consulta**, asi que aqui lo unico
 * que se ve es que no hay fila: `404` sin detalle. Un `403` confirmaria que esa
 * exportacion existe.
 *
 * ## Y por que `show` es un `GET` que escribe
 *
 * Porque emite el enlace de descarga y rota el anterior (ADR-041). Esta
 * declarado en el contrato y en {@see ShowReportExport}: consultar tiene un
 * efecto, y esconderlo seria peor que declararlo. Es la misma forma que
 * `GET /credentials/{uuid}/print`, que sella la impresion al servirla.
 */
final class ReportExportController extends Controller
{
    /** `GET /api/v1/reports/exports` — las 20 mas recientes de quien pregunta. */
    public function index(Request $request, ListReportExports $exports): JsonResponse
    {
        Gate::authorize('view', ReportExport::class);

        return (new ReportExportCollectionResource($exports->handle(self::actorUserId($request))))->response();
    }

    /** `POST /api/v1/reports/exports` — encola la generacion y responde `202`. */
    public function store(
        RequestReportExportRequest $request,
        RequestReportExport $exports,
        ScopeGuard $scope,
        FeatureGate $features,
    ): JsonResponse {
        $kind = $request->kind();

        $this->assertLicensed($features, $kind);

        try {
            $export = $exports->handle(new RequestReportExportCommand(
                kind: $kind,
                format: $request->exportFormat(),
                parameters: $request->toParameters(),
                // RF-ID-03: el alcance lo resuelve el servidor a partir del token y
                // queda congelado en la fila. Quien pide no puede elegirlo ni
                // ampliarlo, y el trabajo no lo recalcula (decision 1).
                scope: $scope->scopeOf($request->user()),
                requestedByUserId: self::actorUserId($request),
            ));
        } catch (ReportExportAlreadyInProgress $conflict) {
            return $this->inProgress($conflict);
        }

        return (new ReportExportResource($export))
            ->response()
            // `202` y no `201`: lo que existe al responder es la PETICION, no el
            // fichero. El panel sondea la lista hasta verla `completed`.
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * `GET /api/v1/reports/exports/{uuid}` — estado y, si esta lista, un enlace
     * **nuevo**.
     */
    public function show(string $uuid, Request $request, ShowReportExport $exports): JsonResponse
    {
        Gate::authorize('download', ReportExport::class);

        $issued = $exports->handle($uuid, self::actorUserId($request));

        if ($issued === null) {
            // No existe, o es de otra persona. Sin detalle y sin distinguir los dos
            // casos: enumerarlos confirmaria que una exportacion ajena existe.
            throw new NotFoundHttpException;
        }

        return (new ReportExportResource($issued->export, $issued))->response();
    }

    /**
     * La comprobacion de licencia, una por clase de informe.
     *
     * **Se hace antes de tocar nada**, de modo que un `402` no deja fila creada ni
     * trabajo encolado: la degradacion tiene que notarse donde se pide, no en la
     * lista media hora despues.
     */
    private function assertLicensed(FeatureGate $features, ReportExportKind $kind): void
    {
        $availability = $features->statusOf(match ($kind) {
            ReportExportKind::Period => Feature::AdvancedReports,
            ReportExportKind::Payroll => Feature::PayrollExport,
        });

        if (! $availability->enabled) {
            throw FeatureNotLicensed::from($availability);
        }
    }

    /**
     * El `409` con la exportacion en curso dentro del cuerpo.
     *
     * La trae la propia excepcion: componerla aqui con una consulta propia abriria
     * un segundo camino de lectura de la tabla desde el borde, y ademas podria
     * devolver otra fila distinta de la que provoco el choque.
     */
    private function inProgress(ReportExportAlreadyInProgress $conflict): JsonResponse
    {
        if ($conflict->current === null) {
            // La que ocupaba el turno termino entre el choque contra el indice y la
            // relectura. El `409` sigue siendo correcto —esta peticion no llego a
            // crearse— y lo unico util que se puede decir es que se reintente.
            return ProblemDetails::reportExportInProgress(
                'Tenias otro informe en curso al pedir este. Vuelve a intentarlo.',
                [],
            );
        }

        return ProblemDetails::reportExportInProgress(
            'Espera a que termine el informe que pediste a las '
            .$conflict->current->requestedAt->format('H:i').' UTC. '
            .'Podras descargarlo desde esta misma pantalla.',
            ReportExportResource::payload($conflict->current),
        );
    }

    /**
     * La cuenta que pide, que es tambien la dueña de la exportacion.
     *
     * La policy ya ha corrido cuando se llega aqui y tipa {@see ManagementActor},
     * asi que llegar sin actor es imposible salvo que alguien retire la
     * autorizacion. Se comprueba igualmente y se rompe en voz alta: una
     * exportacion sin dueño no tendria quien la consultara, y **con dueño
     * equivocado seria de otra persona**.
     */
    private static function actorUserId(Request $request): int
    {
        $identifier = $request->user()?->getAuthIdentifier();

        if (! is_numeric($identifier)) {
            throw new LogicException('La peticion de informe en diferido ha llegado sin cuenta de gestion.');
        }

        return (int) $identifier;
    }
}
