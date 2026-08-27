<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Reporting\Domain\Exception\InvalidDateRange;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use Illuminate\Contracts\Validation\Validator;

/**
 * El rango `?from=&to=` de jornadas, validado una sola vez.
 *
 * ## Por que existe
 *
 * Tres peticiones piden el mismo rango: el registro de un empleado desde el panel
 * (RF-PA-03), el registro propio desde el portal y su descarga (RF-ID-05, RL-05).
 * Las tres tenian estas mismas cuarenta y cinco lineas copiadas, y una de ellas
 * justificaba en su propio docblock por que no debia copiarse —«dos formas de
 * pedir el mismo rango acabarian aceptando rangos distintos»— siendo ya la
 * segunda copia. Esto no es una abstraccion nueva: son tres metodos identicos en
 * un sitio.
 *
 * ## Que valida, y que no
 *
 * **La forma**: dos fechas ISO opcionales y ningun campo mas. Y, cuando llegan
 * las dos, que el rango sea **construible** — el orden de las fechas y el techo
 * de dias los decide {@see DateRange}, que es donde estan escritos, y no una
 * copia de esas reglas en un `rules()`. Dos sitios con el mismo limite acaban con
 * dos limites distintos.
 *
 * **Lo que no hace es completar la omision.** Resolver un parametro que no viene
 * necesita saber que dia es hoy **en la zona del centro del empleado** (RN-04), y
 * eso solo se sabe despues de buscarlo: lo hace el caso de uso, que tiene el
 * puerto `Clock`. Un `FormRequest` que pusiera un valor por omision lo pondria
 * con la zona del servidor, y a las 00:30 de Madrid eso deja fuera la jornada en
 * curso justo en el turno de noche.
 *
 * Si el rango se resuelve con la omision —y por tanto no pasa por esta
 * validacion— y aun asi no vale, la excepcion de dominio se traduce a `422` en
 * `bootstrap/app.php`. La segunda linea sigue estando.
 *
 * ## Rechaza lo desconocido en lugar de ignorarlo
 *
 * Un `?desde=2026-03-01` mal escrito devolveria el mes por omision en silencio, y
 * quien lo envio se iria convencido de estar mirando marzo. Por eso el
 * `withValidator` de {@see RejectsUnknownInput} entra aqui y no en cada peticion:
 * asi ninguna de las tres puede olvidarse de encadenarlo.
 */
trait ValidatesWorkDateRange
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateRangeIsBuildable($validator);
        });
    }

    /**
     * Las dos reglas, para componerlas con las propias de cada peticion.
     *
     * @return array<string, list<string>>
     */
    private function workDateRangeRules(): array
    {
        return [
            'from' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ];
    }

    /**
     * `null` cuando el parametro no viene, que es lo que significa «resuelvelo
     * tu» para el caso de uso. Una cadena vacia tambien: `?from=` es un parametro
     * que el navegador dejo puesto sin valor, no una fecha.
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
