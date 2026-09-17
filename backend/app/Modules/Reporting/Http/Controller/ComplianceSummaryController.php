<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadComplianceSummary;
use App\Modules\Reporting\Http\Request\GetComplianceSummaryRequest;
use App\Modules\Reporting\Http\Resource\ComplianceSummaryResource;
use App\Modules\Reporting\Http\Support\ComplianceSummaryTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;

/**
 * `GET /api/v1/compliance/summary` — las alertas de cumplimiento del periodo
 * (**RF-PA-06**).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, invoca la consulta y
 * serializa el `Resource`. **Ninguna decision vive aqui**: el alcance lo resuelve
 * `ScopeGuard` y lo aplica la consulta, los umbrales salen del perfil de
 * cumplimiento, el instante lo pone el puerto `Clock` y la zona sale del centro.
 *
 * ## No hay `ScopeGuard::ensureReaches()` y no es un olvido
 *
 * Esto es un **listado**, y un listado se acota en la consulta en lugar de
 * devolver `403` (docblock de `ScopeGuard`): quien dirige tres departamentos ve a
 * su gente y no se entera de que existe mas. Un `403` aqui convertiria la pantalla
 * de cumplimiento de un responsable en un error permanente, y filtrar despues de
 * contar daria unos recuentos que describen a personas que quien pregunta no puede
 * ver. El `403` con asiento se reserva para cuando se pide el recurso de una
 * persona concreta, que en este modulo es `GET /employees/{uuid}/workdays`.
 *
 * ## La constancia del acceso tampoco se escribe aqui
 *
 * La escribe la consulta, dentro (RS-05). Si dependiera de una linea de este
 * metodo, el dia que exista un segundo camino hacia la misma lista —y ya existe:
 * `reporting:compliance-metrics`— habria que acordarse de repetirla.
 *
 * ## El techo del rango viene de la configuracion, no de una constante
 *
 * `config/reporting.php` (regla dura 13): un cliente con un servidor mas grande lo
 * sube en su `.env` sin tocar el repositorio. Se lee aqui, en el borde, y se pasa
 * al caso de uso, porque `Application` no lee configuracion (doc 02 §3.5).
 *
 * ## Solo lee, y NO se degrada con la licencia (ADR-023)
 *
 * Es una lectura del registro legal contra los umbrales legales: la unica
 * diferencia con `GET /employees/{uuid}/workdays` es que trae la regla aplicada
 * encima. Por eso **no se consulta `FeatureGate` en este camino** y no hay ningun
 * caso para esta pantalla en `Feature`; `ComplianceSummaryDoesNotDependOnLicenseTest`
 * lo demuestra. Degradarla al caducar la licencia le quitaria al cliente la
 * pantalla con la que responde a una inspeccion.
 */
final class ComplianceSummaryController extends Controller
{
    public function __invoke(
        GetComplianceSummaryRequest $request,
        ReadComplianceSummary $summaries,
        ScopeGuard $scope,
        ComplianceSummaryTelemetry $telemetry,
    ): JsonResponse {
        $criteria = $request->toCriteria($scope);

        $summary = $telemetry->measure(
            static fn () => $summaries->handle($criteria, Config::integer('reporting.compliance.max_range_days')),
        );

        return (new ComplianceSummaryResource($summary))->response();
    }
}
