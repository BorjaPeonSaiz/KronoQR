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
 *   - `POST /employees` con `site_id`: quien lo envia cree haber elegido un
 *     centro, y no hay eleccion posible — hay exactamente uno por instalacion
 *     (ADR-040)—; ignorarlo le dejaria creer que existe otro.
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
                // En el idioma negociado por la peticion, como el resto de los
                // mensajes de validacion (`lang/*/validation.php`).
                $message = __('validation.unknown_field', ['attribute' => $field]);

                $validator->errors()->add(
                    $field,
                    \is_string($message) ? $message : 'The field '.$field.' is not part of this request.',
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
