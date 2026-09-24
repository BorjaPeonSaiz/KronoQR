<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Reporting\Application\Query\AdoptionReportCriteria;
use App\Modules\Reporting\Domain\Exception\InvalidDateRange;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use Illuminate\Contracts\Validation\Validator;

/**
 * El periodo `?from=&to=` del cuadro de impacto, declarado una sola vez
 * (**RF-IN-08**).
 *
 * ## Por que existe, en una frase
 *
 * Porque la consulta y su descarga tienen que pedir **exactamente el mismo
 * periodo**: con dos listas de reglas separadas bastaria cambiar una para que el
 * fichero que alguien adjunta a una renovacion de licencia describiera un periodo
 * distinto del que estaba mirando en pantalla. Mismo argumento —y mismo patron—
 * que {@see DescribesPeriodReport} en el informe por periodo.
 *
 * ## Aqui NO se aplica el techo de dias, y es deliberado
 *
 * Al contrario que {@see ValidatesWorkDateRange}, que pregunta a
 * {@see DateRange::between()} si el rango es construible y con ello aplica de
 * paso `DateRange::MAXIMUM_DAYS`. Ese trait no sirve para este endpoint por una
 * razon concreta: un rango de 400 dias saldria como
 * `urn:kronoqr:problem:validation-failed`, y lo que el contrato promete aqui es
 * `urn:kronoqr:problem:report-too-large`, que es el que dice que hay que
 * **acortar el periodo**. Un cliente que distinga los dos —y el panel lo
 * distingue, porque uno se pinta junto al campo y el otro es un aviso de la
 * pantalla— recibiria el equivocado.
 *
 * Asi que aqui se valida solo lo que es una **peticion mal formada**: fechas que
 * no son fechas y un rango invertido. El techo lo aplica el caso de uso con
 * {@see DateRange::spanInDays()}, sobre el rango ya resuelto y por tanto tambien
 * cuando llega a medias.
 *
 * ## Rechaza lo desconocido en lugar de ignorarlo
 *
 * Un `?desde=2026-03-01` mal escrito devolveria el mes anterior por omision en
 * silencio, y quien lo envio se iria convencido de estar mirando marzo.
 */
trait DescribesAdoptionPeriod
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->validatePeriodIsNotInverted($validator);
        });
    }

    /**
     * Las dos reglas del periodo, para componerlas con las propias de cada
     * peticion —la descarga añade `format`—.
     *
     * **Las dos son opcionales**, al contrario que en el informe por periodo.
     * Alli, quien pide un informe de horas ha elegido un periodo y un rango que
     * nadie pidio acaba en una reunion de nomina; aqui la pantalla **se abre
     * sola** con el mes anterior, que es la pregunta que trae quien entra a ver si
     * el sistema esta sirviendo. La omision la resuelve el caso de uso, que es
     * quien sabe que mes es el anterior **en la zona del centro** (ADR-040).
     *
     * @return array<string, list<string>>
     */
    private function adoptionPeriodRules(): array
    {
        return [
            'from' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ];
    }

    /**
     * `null` cuando el parametro no viene, que es lo que significa «resuelvelo tu»
     * para el caso de uso.
     *
     * **`?from=` con valor vacio tambien llega como `null`**, y no hace falta
     * comprobarlo aqui: el middleware `ConvertEmptyStringsToNull` lo convierte antes
     * de que la peticion llegue a este rasgo. Un `!== ''` de mas seria una rama que
     * ninguna prueba puede recorrer, y una rama inalcanzable invita a creer que el
     * caso se contempla en algun sitio mas.
     */
    private function isoDateParameter(string $parameter): ?string
    {
        $value = $this->query($parameter);

        return \is_string($value) ? $value : null;
    }

    public function toCriteria(): AdoptionReportCriteria
    {
        return new AdoptionReportCriteria(
            from: $this->isoDateParameter('from'),
            to: $this->isoDateParameter('to'),
        );
    }

    /**
     * Que `from` no sea posterior a `to`, preguntandoselo al objeto de valor que
     * lo define en lugar de copiar la comparacion.
     *
     * Solo cuando llegan las dos: con una sola, la otra la pone el caso de uso y
     * todavia no se sabe cual sera. {@see DateRange::spanInDays()} no aplica el
     * techo de dias, que es justo lo que se quiere aqui.
     */
    private function validatePeriodIsNotInverted(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $from = $this->isoDateParameter('from');
        $to = $this->isoDateParameter('to');

        if ($from === null || $to === null) {
            return;
        }

        try {
            DateRange::spanInDays($from, $to);
        } catch (InvalidDateRange $invalid) {
            $validator->errors()->add('from', $invalid->getMessage());
        }
    }
}
