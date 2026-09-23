<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\Support\ReportDataset;
use App\Modules\Reporting\Http\Request\ExportPayrollRequest;
use App\Modules\Reporting\Http\Response\PayrollExportDocument;
use App\Modules\Reporting\Http\Support\PeriodReportExportTelemetry;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PayrollLayoutProvider;
use App\Modules\Shared\Domain\Exception\FeatureNotLicensed;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/v1/reports/payroll-export` — la salida a nomina, en el acto
 * (**RF-IN-07**).
 *
 * ## No calcula nada nuevo
 *
 * Es **el informe por periodo por empleado** pasado por la plantilla configurable
 * del cliente: la misma {@see GeneratePeriodReport}, los mismos presupuestos
 * sincronos de `config/reporting.php` y el mismo `422` cuando no cabe. Si tuviera
 * consulta propia, el fichero que se importa en la nomina y el cuadro que RRHH
 * mira en pantalla podrian discrepar, y el que se creeria seria el equivocado.
 *
 * El doc 05 §8 acota el alcance y conviene repetirlo aqui: **no se calcula la
 * nomina**, ni pluses, ni complementos. Salen horas.
 *
 * ## Tres comprobaciones, y dicen cosas distintas
 *
 *   1. **Ambito `reports:*`**, en el middleware. Deja fuera al `auditor` —que
 *      lleva `reports:legal`— y al `responsable_departamento`, que no lleva
 *      ninguno de informes.
 *   2. **`PayrollExportPolicy`**, en el `FormRequest`: `{admin, rrhh}` (regla
 *      dura 18). Las dos primeras responden `403`.
 *   3. **`Feature::PayrollExport`**, aqui: `402`. **No tener contratada la salida
 *      a nomina no es lo mismo que no tener permiso** y el cliente merece
 *      distinguirlo — es lo que convierte un aviso de licencia en una renovacion
 *      en lugar de en una llamada de soporte.
 *
 * Se comprueba la licencia **antes** de construir la consulta, para que una
 * instalacion sin la funcionalidad no llegue siquiera a tocar la base de datos.
 * Y es la primera consumidora de `PayrollExport`, que estaba en el catalogo desde
 * la tarea 5.3 sin que nada la mirara.
 *
 * **Esto no es el registro legal.** La exportacion para la Inspeccion
 * (`GET /reports/legal-export`) no se degrada jamas (RL-06, regla dura 15); esta
 * es material de gestion y ADR-023 la clasifica como accesoria.
 *
 * ## La plantilla la pone el servidor
 *
 * Ni un solo parametro de la peticion toca el formato del fichero: columnas,
 * separador, horas, fechas, codificacion y cabecera salen de
 * `installation_settings` por el puerto {@see PayrollLayoutProvider} (RF-PD-01,
 * ADR-017). Que no se pueda pedir «dame el CSV con comas» es deliberado: el
 * formato lo fija quien administra la instalacion una vez, no quien descarga cada
 * mes, porque lo que tiene que encajar es el importador del programa de nomina.
 *
 * ## El asiento no se escribe aqui
 *
 * Lo escribe la consulta, dentro y **antes** de devolver el informe (RS-05, regla
 * dura 6). Lo unico que este endpoint aporta es **que** conjunto se divulgo
 * —`payroll_export`— y **en que** salio. Un segundo asiento desde aqui daria dos
 * entradas sobre la misma divulgacion.
 *
 * ## `GET` aunque quede auditado
 *
 * Solo lee. Mismo criterio que la exportacion legal y que la descarga del informe;
 * un `POST` ademas impediria enlazar la descarga.
 */
final class PayrollExportController extends Controller
{
    public function __invoke(
        ExportPayrollRequest $request,
        GeneratePeriodReport $reports,
        PeriodReportExportTelemetry $telemetry,
        ScopeGuard $scope,
        PayrollExportDocument $documents,
        PayrollLayoutProvider $layouts,
        InstallationSiteProvider $installation,
        FeatureGate $features,
    ): StreamedResponse {
        $availability = $features->statusOf(Feature::PayrollExport);

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
            dataset: ReportDataset::PayrollExport,
        ));

        return $documents->respond($report, $this->layoutFor($layouts, $installation), $format);
    }

    /**
     * La plantilla del centro de la instalacion.
     *
     * Se resuelve **despues** del informe a proposito: si no hay centro, quien
     * responde es la consulta con su `409` (RF-PD-03, ADR-040), que es el error
     * correcto y el que ya conoce el panel. Pedir la plantilla antes convertiria
     * ese estado de la instalacion en un fallo distinto segun el endpoint.
     *
     * Sin centro **no se llega aqui**; el `shipped()` no es un respaldo silencioso
     * sino la unica salida sensata si alguna vez se llegara, y es la misma
     * plantilla que rige una instalacion sin ninguna fila configurada.
     */
    private function layoutFor(PayrollLayoutProvider $layouts, InstallationSiteProvider $installation): PayrollLayout
    {
        $site = $installation->installationSite();

        if ($site === null) {
            throw new InstallationSiteMissing;
        }

        return $layouts->forSite($site->id);
    }
}
