<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadAdoptionReport;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Http\Request\GetAdoptionReportRequest;
use App\Modules\Reporting\Http\Resource\AdoptionReportResource;
use App\Modules\Reporting\Http\Support\AdoptionReportTelemetry;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\Exception\FeatureNotLicensed;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;

/**
 * `GET /api/v1/reports/adoption` — el cuadro de impacto y adopcion
 * (**RF-IN-08**, **RNF-D-01**).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, comprueba la licencia,
 * invoca la consulta y serializa el `Resource`. **Ninguna decision vive aqui**: el
 * periodo lo resuelve la consulta —que es quien sabe que mes es el anterior en la
 * zona del centro—, la aritmetica el dominio y los objetivos del §1.3 el enumerado
 * de indicadores.
 *
 * ## Tres comprobaciones, y dicen cosas distintas
 *
 *   1. **Ambito `reports:*`**, en el middleware. Deja fuera al `auditor` —que lleva
 *      `reports:legal`— y al `responsable_departamento`, que no lleva ninguno de
 *      informes (§7.3).
 *   2. **`AdoptionReportPolicy`**, en el `FormRequest`: `{admin, rrhh}` (regla dura
 *      18). Las dos primeras responden `403`.
 *   3. **`Feature::ImpactDashboard`**, aqui: `402`. **No tener contratado el cuadro
 *      de impacto no es lo mismo que no tener permiso** y el cliente merece
 *      distinguirlo — es lo que convierte un aviso de licencia en una renovacion en
 *      lugar de en una llamada de soporte.
 *
 * Se comprueba la licencia **antes** de construir la consulta, para que una
 * instalacion sin la funcionalidad no llegue siquiera a tocar la base de datos. Es
 * ademas la **primera consumidora** de `ImpactDashboard`, que estaba en el catalogo
 * desde la tarea 5.3 sin que nada la mirara.
 *
 * **Esto no es el registro legal.** El fichaje, la consulta de jornadas, el portal
 * del empleado y la exportacion para la Inspeccion no se degradan jamas (RL-06,
 * regla dura 15); esto es material de direccion y ADR-023 lo clasifica como
 * accesorio. Y ahi hay una ironia que conviene tener escrita: el cuadro que sostiene
 * la renovacion de la licencia se apaga cuando la licencia caduca. Es correcto
 * —degradar lo accesorio y no el registro es exactamente la frontera de ADR-023— y
 * el aviso del `402` es lo que lleva a renovar.
 *
 * ## No hay `ScopeGuard`, y no es un olvido
 *
 * El cuadro es de la **instalacion entera** (regla dura 21): sin desglose por
 * departamento, no hay nada que acotar. El argumento completo esta en
 * `AdoptionReportPolicy` y en `ReadAdoptionReport`.
 *
 * ## Y no escribe en `audit_log`
 *
 * Al contrario que el informe por periodo y que la vista de cumplimiento. Lo que
 * sale son doce agregados sin un solo identificador, asi que no hay divulgacion de
 * datos personales que registrar (RS-05 no aplica). **La descarga si se audita**:
 * ver `AdoptionReportExportController`.
 *
 * ## Los techos vienen de la configuracion, no de una constante
 *
 * `config/reporting.php` (regla dura 13): un cliente con un servidor mas grande los
 * sube en su `.env` sin tocar el repositorio. Se leen aqui, en el borde, y se pasan
 * al caso de uso, porque `Application` no lee configuracion (doc 02 §3.5).
 */
final class AdoptionReportController extends Controller
{
    public function __invoke(
        GetAdoptionReportRequest $request,
        ReadAdoptionReport $reports,
        AdoptionReportTelemetry $telemetry,
        FeatureGate $features,
    ): JsonResponse {
        $availability = $features->statusOf(Feature::ImpactDashboard);

        if (! $availability->enabled) {
            throw FeatureNotLicensed::from($availability);
        }

        $criteria = $request->toCriteria();

        $report = $telemetry->measure(ReportDelivery::Json, static fn () => $reports->handle(
            $criteria,
            maxRangeDays: Config::integer('reporting.adoption.max_range_days'),
            maxRows: Config::integer('reporting.period.max_rows'),
        ));

        return (new AdoptionReportResource($report))->response();
    }
}
