<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Http\Request\ExportPeriodReportRequest;
use App\Modules\Reporting\Http\Response\PeriodReportDocument;
use App\Modules\Reporting\Http\Support\PeriodReportExportTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\Exception\FeatureNotLicensed;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Support\Facades\Config;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/v1/reports/period/export` — el informe de horas por periodo como
 * fichero descargable (**RF-IN-04**).
 *
 * Delgado, como su hermano de este directorio: valida y autoriza el
 * `FormRequest`, invoca **la misma** consulta y devuelve el documento. Ninguna
 * decision vive aqui — ni las columnas, ni el sello, ni el formato de las horas.
 *
 * ## Es la misma consulta, y eso es la mitad del requisito
 *
 * {@see GeneratePeriodReport} con los mismos parametros y los mismos techos, no
 * una SQL propia. Si la exportacion tuviera la suya, el fichero que alguien
 * adjunta a un correo y la tabla que estaba mirando podrian discrepar, y el que
 * se creeria seria el equivocado.
 *
 * ## El asiento de `audit_log` no se escribe aqui, y tampoco se repite
 *
 * Lo escribe la consulta, dentro, **antes** de devolver el informe (RS-05, regla
 * dura 6). Lo unico que añade este endpoint es **en que** salio: el
 * `ReportDelivery` que se le pasa acaba en el campo `format` de ese mismo
 * asiento. Un segundo asiento desde aqui daria dos entradas —«consultado» y
 * «exportado»— sobre exactamente la misma divulgacion, y quien lea el trail
 * tendria que saber emparejarlas.
 *
 * ## `GET` aunque quede auditado
 *
 * Solo lee. Que devuelva un fichero y que deje constancia no lo convierte en una
 * escritura; mismo criterio que la exportacion legal y que la consulta JSON. Un
 * `POST` ademas impediria enlazar la descarga.
 *
 * ## El `uuid` del emisor sale del actor autenticado, no de un parametro
 *
 * Quien descarga no puede declarar quien es. El nombre con el que se sella el
 * documento lo resuelve despues un puerto a partir de ese `uuid`.
 */
final class PeriodReportExportController extends Controller
{
    public function __invoke(
        ExportPeriodReportRequest $request,
        GeneratePeriodReport $reports,
        PeriodReportExportTelemetry $telemetry,
        ScopeGuard $scope,
        PeriodReportDocument $documents,
        FeatureGate $features,
    ): StreamedResponse {
        /*
         * MISMA COMPROBACION DE LICENCIA QUE LA CONSULTA, y tiene que serlo: lo
         * que sale es exactamente lo mismo (ADR-023, tarea 5.3). Un endpoint de
         * descarga con la degradacion mas floja que su consulta es la forma
         * habitual de que la degradacion no sirva de nada — el mismo argumento
         * por el que comparte ambito, policy y limitador.
         *
         * **Esto NO es la exportacion para la Inspeccion.** Aquella es
         * `GET /reports/legal-export`, es registro legal y no se degrada jamas
         * (RL-06, regla dura 15). El texto del `402` la nombra para que quien se
         * encuentre el aviso sepa por donde seguir.
         */
        $availability = $features->statusOf(Feature::AdvancedReports);

        if (! $availability->enabled) {
            throw FeatureNotLicensed::from($availability);
        }

        $query = $request->toQuery($scope);
        $format = $request->exportFormat();

        $report = $telemetry->measure($query, $format, static fn () => $reports->handle(
            $query,
            maxRangeDays: Config::integer('reporting.period.max_range_days'),
            maxRows: Config::integer('reporting.period.max_rows'),
            delivery: $format,
        ));

        return $documents->respond($report, $format, $this->actorUuid($request));
    }

    /**
     * El `uuid` publico de la cuenta que pidio la descarga.
     *
     * La policy ya ha corrido cuando se llega aqui —`authorize()` del
     * `FormRequest`— y esa policy tipa {@see ManagementActor}, asi que llegar sin
     * actor es imposible salvo que alguien retire la autorizacion. Se comprueba
     * igualmente y se rompe en voz alta: sellar un documento con un emisor vacio
     * seria peor que no entregarlo.
     */
    private function actorUuid(ExportPeriodReportRequest $request): string
    {
        $actor = $request->user();

        if (! $actor instanceof ManagementActor) {
            throw new LogicException('La descarga del informe ha llegado sin actor de gestion.');
        }

        return $actor->actorUuid();
    }
}
