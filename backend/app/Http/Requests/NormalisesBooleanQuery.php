<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Normaliza los booleanos de la cadena de consulta antes de validarlos.
 *
 * **Por que existe.** El contrato declara varios filtros como
 * `schema: {type: boolean}` en `query` —`pending` en el tablero de credenciales,
 * `include_open_shifts` en el informe por periodo— y la serializacion estandar
 * de OpenAPI para eso es el literal `?include_open_shifts=true`. Es la que
 * genera `@kronoqr/web-kit` (`String(true)`) y la que manda el panel. La regla
 * `boolean` de Laravel solo acepta `true|false|1|0|'1'|'0'`, asi que ese literal
 * respondia `422` y la casilla del panel no servia para nada. Las pruebas que
 * existian mandaban `'1'`, que si pasa, y por eso nadie lo veia. Paso primero en
 * `pending` (RF-QR-08) y se arreglo alli a medida; al repetirse en
 * `include_open_shifts` (RF-IN-01) el arreglo pasa a estar escrito una vez.
 *
 * **Que hace.** Para cada campo cuyas reglas incluyan `boolean`, si lo que llega
 * es una cadena que `filter_var` reconoce como booleano (`true/false`, `1/0`,
 * `yes/no`, `on/off`) la sustituye por el booleano. Lo que NO reconoce se deja
 * tal cual para que la regla lo rechace: un filtro mal escrito tiene que doler,
 * no colarse como `false` y devolver «todo» en silencio.
 *
 * Solo toca cadenas: un cuerpo JSON ya trae booleanos de verdad y no pasa por
 * aqui. Los campos se deducen de `rules()`, como hace {@see RejectsUnknownInput}:
 * asi no hay una segunda lista que mantener. Y vive fuera de los modulos por lo
 * mismo que aquel: no sabe nada de ninguno.
 */
trait NormalisesBooleanQuery
{
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach ($this->booleanFields() as $field) {
            $value = $this->input($field);

            if (! \is_string($value)) {
                continue;
            }

            $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($boolean !== null) {
                $normalised[$field] = $boolean;
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return list<string>
     */
    private function booleanFields(): array
    {
        $fields = [];

        foreach ($this->rules() as $field => $rules) {
            if (\in_array('boolean', $rules, true)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }
}
