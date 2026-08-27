<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Application\Query\EmployeeWorkDayRange;
use App\Modules\Reporting\Http\Policy\SelfJournalPolicy;
use App\Modules\Reporting\Http\Support\PortalEmployee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/me/workdays` — el rango que el empleado consulta de **su propio**
 * registro (RF-ID-05, RL-05).
 *
 * ## Es el hermano de `ListEmployeeWorkDaysRequest` con dos diferencias
 *
 * 1. **La policy**: aqui autoriza {@see SelfJournalPolicy}, que comprueba que
 *    quien porta el token es una sesion de portal y no una cuenta de gestion. Se
 *    llama por su nombre y no por el `Gate` global, por lo mismo que explica la
 *    propia policy.
 * 2. **El empleado no llega por la URL**: lo pone {@see PortalEmployee} a partir
 *    del token, que es lo unico que el cliente no puede falsificar. Es la mitad
 *    de RF-ID-07 que no es un ambito.
 *
 * Todo lo demas —las dos fechas opcionales, el techo de 366 dias, que la omision
 * la resuelva el caso de uso con la zona del centro— es identico, y a proposito:
 * dos formas de pedir el mismo rango acabarian aceptando rangos distintos. Por
 * eso ese «todo lo demas» ya no esta copiado aqui, sino en
 * {@see ValidatesWorkDateRange}: mientras lo estuvo, esta clase era literalmente
 * la segunda copia que su propio parrafo desaconsejaba.
 */
final class ListMyWorkDaysRequest extends FormRequest
{
    use ValidatesWorkDateRange;

    public function authorize(): bool
    {
        return (new SelfJournalPolicy)->view($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->workDateRangeRules();
    }

    public function toQuery(): EmployeeWorkDayRange
    {
        return new EmployeeWorkDayRange(
            employeeUuid: PortalEmployee::uuidOf($this),
            from: $this->isoDate('from'),
            to: $this->isoDate('to'),
            // Es su propio registro: no hay divulgacion a un tercero que
            // registrar (RS-05). El caso de uso explica por que.
            selfService: true,
        );
    }
}
