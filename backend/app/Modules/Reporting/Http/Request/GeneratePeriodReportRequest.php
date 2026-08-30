<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/reports/period` — que informe se pide (**RF-IN-01**, RF-IN-02).
 *
 * ## `from` y `to` son obligatorias, al contrario que en el detalle de jornada
 *
 * Alli tienen omision porque la pantalla se abre sola con el ultimo mes. Aqui no
 * hay pantalla que se abra sola: quien pide un informe de horas ha elegido un
 * periodo, y un informe generado sobre un rango que nadie pidio es exactamente
 * la cifra que despues alguien lleva a una reunion de nomina creyendo que es
 * otra. Ademas evita la ambiguedad de la zona: sin fechas habria que decidir que
 * dia es hoy, y ese calculo solo tiene sentido en la zona del centro.
 *
 * ## Rechaza lo desconocido en lugar de ignorarlo
 *
 * Un `?granularidad=mes` devolveria el informe diario en silencio y quien lo
 * escribio se iria convencido de haber pedido meses. Lo hereda de
 * {@see ValidatesWorkDateRange}, que ademas comprueba que el rango sea
 * **construible** preguntandoselo a {@see DateRange} en lugar de copiar sus
 * limites aqui.
 *
 * ## `department_id` y `employee_uuid` se validan contra la tabla, no contra el
 * alcance
 *
 * Uno inexistente es un `422` —hay una errata que corregir— y uno existente pero
 * fuera del alcance devuelve un informe vacio, no un `403`: son filtros, no la
 * peticion de un recurso ajeno. Mismo criterio que `GET /employees` y que el
 * panel de presencia.
 *
 * ## Las reglas no viven aqui, sino en {@see DescribesPeriodReport}
 *
 * Las comparte con la descarga del mismo informe (RF-IN-04, tarea 2.9). Estan en
 * un sitio para que la pantalla y el fichero no puedan aceptar parametros
 * distintos: si divergieran, el fichero que alguien adjunta a un correo
 * describiria un informe que no es el que estaba mirando.
 *
 * ## El alcance no se elige aqui
 *
 * Lo resuelve `ScopeGuard` a partir del token y entra **dentro** de la consulta
 * (RF-ID-03). No hay ningun parametro con el que ampliarlo.
 */
final class GeneratePeriodReportRequest extends FormRequest
{
    use DescribesPeriodReport;

    public function authorize(): bool
    {
        return Gate::allows('view', PeriodReport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->periodReportRules();
    }
}
