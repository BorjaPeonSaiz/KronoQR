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
 * ## `format` es un enumerado de dos valores, y eso es el contrato
 *
 * `csv` desde la tarea 1.11 y `pdf` desde la 2.2.0 (PR19; el Anexo B del doc 01
 * exige los dos). El PDF llego como **otro valor del mismo enumerado**, es decir
 * un cambio aditivo que no rompe a ningun cliente (ADR-012): el portal que ya
 * escribia `?format=csv` no ha tenido que cambiar la URL.
 *
 * **Se valida y no se ignora**: `?format=xlsx` devuelve `422` con el campo
 * señalado, en lugar de servir un CSV a quien pidio otra cosa. Sin XLSX a
 * proposito —RF-IN-04 lo exige para los informes de gestion, donde alguien va a
 * seguir calculando sobre la hoja; para el historico personal de una persona no
 * aporta nada sobre CSV y es un formato propietario—.
 *
 * **El mismo rango y el mismo limite para los dos formatos.** El techo de 366
 * dias de {@see ValidatesWorkDateRange} y la zona `throttle:portal` de la ruta
 * son los mismos: el PDF no abre una puerta mas ancha que el CSV.
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

    /** El formato por omision: el portal que no dice nada recibe el CSV. */
    public const string CSV = 'csv';

    /** El documento sellado que una persona presenta ante un tercero (PR19). */
    public const string PDF = 'pdf';

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
            'format' => ['sometimes', 'string', 'in:'.self::CSV.','.self::PDF],
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

    /** `true` si se pidio el PDF; cualquier otro valor valido es el CSV. */
    public function wantsPdf(): bool
    {
        return $this->query('format') === self::PDF;
    }
}
