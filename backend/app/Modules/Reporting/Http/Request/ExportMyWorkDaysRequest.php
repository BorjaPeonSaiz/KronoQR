<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Application\Query\EmployeeWorkDayRange;
use App\Modules\Reporting\Http\Policy\SelfJournalPolicy;
use App\Modules\Reporting\Http\Support\PortalEmployee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/me/export` — la descarga del historico propio (RF-ID-05, RL-05).
 *
 * ## `format` es un enumerado de un solo valor, y eso es el contrato
 *
 * Hoy solo existe `csv`. El PDF llega con la maquinaria de exportacion de la
 * tarea 2.9 y sera **otro valor del mismo enumerado**, es decir un cambio
 * aditivo que no rompe a ningun cliente (ADR-012). Declararlo ya como parametro
 * —en vez de no tenerlo y añadirlo despues— es lo que permite que el portal
 * escriba `?format=csv` desde el primer dia y no tenga que cambiar la URL cuando
 * llegue el segundo formato.
 *
 * **Se valida y no se ignora**: `?format=xlsx` devuelve `422` con el campo
 * señalado, en lugar de servir un CSV a quien pidio otra cosa. Sin XLSX a
 * proposito —RF-IN-04 lo exige para los informes de gestion, donde alguien va a
 * seguir calculando sobre la hoja; para el historico personal de una persona no
 * aporta nada sobre CSV y es un formato propietario—.
 *
 * ## Aqui si es `422` y no `400`
 *
 * Al reves que en el acceso al portal. Alli el `422` habria chocado con el `401`
 * de las credenciales y ademas habria dicho **cual** de los dos campos falla;
 * aqui no hay ningun secreto que proteger y quien recibe la respuesta es una
 * persona autenticada que puede corregir su URL.
 *
 * ## La policy y el empleado, igual que en `ListMyWorkDaysRequest`
 *
 * {@see SelfJournalPolicy::export()} autoriza, y el empleado sale del token con
 * {@see PortalEmployee}. Sin `{uuid}` en la ruta no hay nada que manipular. El
 * rango —y su rechazo de campos desconocidos— es el de
 * {@see ValidatesWorkDateRange}, el mismo de las otras dos peticiones.
 */
final class ExportMyWorkDaysRequest extends FormRequest
{
    use ValidatesWorkDateRange;

    /**
     * El unico formato de esta fase. La tarea 2.9 añade `pdf` aqui y en el
     * contrato, y en ningun sitio mas.
     */
    public const string CSV = 'csv';

    public function authorize(): bool
    {
        return (new SelfJournalPolicy)->export($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->workDateRangeRules(),
            'format' => ['sometimes', 'string', 'in:'.self::CSV],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'format.in' => 'El unico formato disponible es CSV. El PDF llegara en una version posterior.',
        ];
    }

    public function toQuery(): EmployeeWorkDayRange
    {
        return new EmployeeWorkDayRange(
            employeeUuid: PortalEmployee::uuidOf($this),
            from: $this->isoDate('from'),
            to: $this->isoDate('to'),
            // Descargar lo propio tampoco es divulgar a un tercero (RS-05).
            selfService: true,
        );
    }
}
