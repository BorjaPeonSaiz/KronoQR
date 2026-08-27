<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

/**
 * Rechaza los campos que el endpoint no conoce, en lugar de ignorarlos.
 *
 * **Por que importa y no es celo.** El contrato declara
 * `additionalProperties: false` en cada peticion, asi que el cliente generado no
 * puede enviar un campo de mas; pero un cliente escrito a mano si, y Laravel lo
 * ignoraria en silencio. Dos casos concretos de esta tarea lo hacen relevante:
 *
 *   - `POST /employees` con `employee_code`: quien lo envia se va convencido de
 *     haber fijado el codigo, y el servidor genero otro (RF-ID-06).
 *   - `PATCH /departments/{id}` con `site_id`: quien lo envia cree haber movido
 *     el departamento de centro, y no ha movido nada — pero con eso los
 *     empleados habrian cambiado de zona horaria y de jornada (RN-05).
 *
 * En los dos, fallar en voz alta es mejor que acertar por casualidad.
 *
 * Vive fuera de los modulos porque no sabe nada de ninguno: solo compara lo que
 * llega con las claves que el propio `FormRequest` declara en `rules()`.
 */
trait RejectsUnknownInput
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->unknownFields() as $field) {
                $validator->errors()->add(
                    $field,
                    'El campo '.$field.' no forma parte de esta peticion.',
                );
            }
        });
    }

    /**
     * @return list<string>
     */
    private function unknownFields(): array
    {
        // Las reglas anidadas (`contacto.email`) declaran su raiz aparte, asi
        // que basta comparar el primer segmento para no rechazar un objeto
        // legitimo el dia que exista uno.
        $known = [];

        foreach (array_keys($this->rules()) as $rule) {
            $known[explode('.', (string) $rule)[0]] = true;
        }

        $unknown = [];

        foreach (array_keys($this->all()) as $field) {
            if (! \array_key_exists((string) $field, $known)) {
                $unknown[] = (string) $field;
            }
        }

        return $unknown;
    }
}
