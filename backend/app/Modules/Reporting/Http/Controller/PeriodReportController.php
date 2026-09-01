<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Http\Request\GeneratePeriodReportRequest;
use App\Modules\Reporting\Http\Resource\PeriodReportResource;
use App\Modules\Reporting\Http\Support\PeriodReportTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\Exception\FeatureNotLicensed;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;

/**
 * `GET /api/v1/reports/period` — horas trabajadas por periodo, con sus
 * agregados y su comparativa contra lo contratado (**RF-IN-01**, RF-IN-02,
 * RF-IN-03).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, invoca la consulta y
 * serializa el `Resource`. **Ninguna decision vive aqui**: el alcance lo resuelve
 * `ScopeGuard` y lo aplica la consulta, el instante y la zona los pone el caso de
 * uso, los criterios de inclusion los declara el propio informe y el presupuesto
 * sincrono se comprueba dentro.
 *
 * ## No hay `ScopeGuard::ensureReaches()` y no es un olvido
 *
 * Esto es un **listado agregado**, y un listado se acota en la consulta en lugar
 * de devolver `403` (docblock de `ScopeGuard`). Un `403` aqui convertiria la
 * pantalla de informes de un responsable en un error permanente, y filtrar
 * despues de agregar daria totales que describen horas de personas que quien
 * pregunta no puede ver. El `403` con asiento se reserva para cuando se pide el
 * recurso de una persona concreta, que en este modulo es
 * `GET /employees/{uuid}/workdays`.
 *
 * ## La constancia del acceso tampoco se escribe aqui
 *
 * La escribe la consulta, dentro (RS-05). Si dependiera de una linea de este
 * metodo, el dia que la exportacion de la tarea 2.9 use el mismo informe habria
 * que acordarse de repetirla — y la 2.9 no la va a repetir, la va a heredar.
 *
 * ## Los dos techos vienen de la configuracion, no de constantes
 *
 * `config/reporting.php` (regla dura 13): un cliente con un servidor mas grande
 * los sube en su `.env` sin tocar el repositorio. Se leen aqui, en el borde, y se
 * pasan al caso de uso: `Application` no lee configuracion (doc 02 §3.5, sin
 * facades por debajo de `Http`).
 *
 * ## Solo lee, y no puede hacer otra cosa
 *
 * Ninguna respuesta de este endpoint cambia el registro. Que quede auditado
 * (RS-05) no lo convierte en una escritura, igual que en la exportacion legal.
 *
 * ## Es funcionalidad ACCESORIA, y se degrada (tarea 5.3)
 *
 * [ADR-023](../../../../../../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md)
 * lo clasifica como degradable: *«informes avanzados y comparacion entre
 * periodos: se desactivan; el registro y su exportacion legal siguen
 * disponibles»*. Con la licencia caducada, ausente o ilegible, este endpoint
 * responde `402` con el aviso de licencia —que **dice cual es la alternativa**—
 * y no un error generico, que es lo que exige la verificacion de ADR-019.
 *
 * **Y lo que NO se degrada, que es la mitad importante:**
 * `GET /employees/{uuid}/workdays` (la consulta del registro),
 * `GET /reports/legal-export` (la Inspeccion, RL-06), `GET /me/workdays` y
 * `GET /me/export` (el portal, RL-05) siguen respondiendo con normalidad. El
 * texto del `402` los nombra uno a uno, porque quien se encuentra el aviso
 * necesita saber por donde sacar las horas de este mes mientras se renueva.
 *
 * La comprobacion se hace **aqui, en el borde**, con el resultado ya resuelto
 * por el `FeatureGate`: el caso de uso no consulta la licencia, igual que no
 * consulta la configuracion. Y va **antes** de generar nada, porque generar un
 * informe cuesta cruzar la plantilla con el calendario.
 */
final class PeriodReportController extends Controller
{
    public function __invoke(
        GeneratePeriodReportRequest $request,
        GeneratePeriodReport $reports,
        PeriodReportTelemetry $telemetry,
        ScopeGuard $scope,
        FeatureGate $features,
    ): JsonResponse {
        $availability = $features->statusOf(Feature::AdvancedReports);

        if (! $availability->enabled) {
            throw FeatureNotLicensed::from($availability);
        }

        $query = $request->toQuery($scope);

        $report = $telemetry->measure($query, static fn () => $reports->handle(
            $query,
            maxRangeDays: Config::integer('reporting.period.max_range_days'),
            maxRows: Config::integer('reporting.period.max_rows'),
        ));

        return (new PeriodReportResource($report))->response();
    }
}
