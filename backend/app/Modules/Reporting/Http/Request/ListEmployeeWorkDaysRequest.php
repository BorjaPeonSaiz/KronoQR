<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Reporting\Application\Query\EmployeeWorkDayRange;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Reporting\Domain\Exception\InvalidDateRange;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/employees/{uuid}/workdays` — el rango de jornadas que se consulta
 * (RF-PA-03).
 *
 * ## Los dos parametros son opcionales y **no se completan aqui**
 *
 * Resolver la omision necesita saber que dia es hoy **en la zona del centro del
 * empleado** (RN-04), y eso solo se sabe despues de buscarlo. Lo hace
 * {@see ReadEmployeeWorkDays}, que tiene
 * el puerto `Clock` y el de la consulta. Si el `FormRequest` pusiera un valor por
 * omision, lo pondria con la zona del servidor, y a las 00:30 de Madrid eso deja
 * fuera la jornada en curso justo en el turno de noche.
 *
 * ## Que se valida y que no
 *
 * Aqui se comprueba **la forma** —fechas ISO y nada mas— y, cuando llegan las
 * dos, tambien que el rango sea construible: el orden y el techo de dias los
 * decide {@see DateRange}, que es donde estan escritos, y no una copia de esas
 * reglas en un `rules()`. Dos sitios con el mismo limite acaban con dos limites
 * distintos.
 *
 * La segunda linea sigue estando: si el rango se resuelve con la omision —y por
 * tanto no pasa por esta validacion— y aun asi no vale, la excepcion de dominio
 * se traduce a `422` en `bootstrap/app.php`.
 *
 * **Rechaza lo desconocido en lugar de ignorarlo.** Un `?desde=2026-03-01` mal
 * escrito devolveria el mes por omision en silencio, y quien lo envio se iria
 * convencido de estar mirando marzo.
 */
final class ListEmployeeWorkDaysRequest extends FormRequest
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function authorize(): bool
    {
        return Gate::allows('view', WorkDayJournal::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateRangeIsBuildable($validator);
        });
    }

    public function toQuery(string $employeeUuid): EmployeeWorkDayRange
    {
        return new EmployeeWorkDayRange(
            employeeUuid: $employeeUuid,
            from: $this->isoDate('from'),
            to: $this->isoDate('to'),
        );
    }

    /**
     * `null` cuando el parametro no viene, que es lo que significa «resuelvelo
     * tu» para el caso de uso. Una cadena vacia tambien: `?from=` es un
     * parametro que el navegador dejo puesto sin valor, no una fecha.
     */
    private function isoDate(string $parameter): ?string
    {
        $value = $this->query($parameter);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * El orden de las fechas y el techo de dias, preguntandoselos al objeto de
     * valor que los define. Solo cuando llegan las dos: con una sola, la otra la
     * pone el caso de uso y todavia no se sabe cual sera.
     */
    private function validateRangeIsBuildable(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $from = $this->isoDate('from');
        $to = $this->isoDate('to');

        if ($from === null || $to === null) {
            return;
        }

        try {
            DateRange::between($from, $to);
        } catch (InvalidDateRange $invalid) {
            $validator->errors()->add('from', $invalid->getMessage());
        }
    }
}
