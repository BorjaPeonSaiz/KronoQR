<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Application\Command\UpdateComplianceProfileCommand;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileFieldType;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /api/v1/compliance-profile` (contrato `UpdateComplianceProfileRequest`).
 *
 * ## Las reglas SE DERIVAN del catalogo de campos
 *
 * Cada campo declara su tipo, su minimo y su maximo en
 * {@see ComplianceProfileField}, que es dominio puro. Este `FormRequest` los
 * traduce a reglas de Laravel; no decide ninguno. Es lo que evita la segunda
 * fuente de verdad que se desincroniza el dia que un maximo cambie.
 *
 * **Y no sustituye a la validacion del dominio.**
 * {@see ComplianceProfileSnapshot::with()} vuelve a comprobar lo mismo dentro del
 * caso de uso, y debe hacerlo: hay caminos que no pasan por HTTP —el asistente de
 * puesta en marcha, la consola— y la garantia no puede depender de por donde se
 * entre. Lo que aporta esta capa es el **mensaje util**: un `422` colgado del
 * campo concreto y en el idioma negociado, en vez de una excepcion generica.
 *
 * ## Los campos no editables no estan, y un intento de tocarlos es `422`
 *
 * `jurisdiction`, `is_default` e `id` no aparecen en `rules()`, asi que
 * `RejectsUnknownInput` los rechaza como campo desconocido. Es lo correcto:
 * ignorarlos en silencio dejaria a quien los envia creyendo que ha cambiado algo.
 *
 * ## El autor no se declara, se toma de la sesion
 *
 * Aceptarlo en el cuerpo permitiria firmar un cambio de umbral legal a nombre de
 * otra persona.
 */
final class UpdateComplianceProfileRequest extends FormRequest
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function authorize(): bool
    {
        return Gate::allows('update', ComplianceProfileSnapshot::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (ComplianceProfileField::cases() as $field) {
            $rules += $this->rulesFor($field);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            if ($this->submittedFields() === []) {
                // Un `PATCH` vacio no es idempotente: es una peticion sin
                // intencion. Decirlo es mas util que devolver el perfil sin
                // cambios y dejar a quien lo mando pensando que guardo algo.
                $message = __('compliance-profile.errors.no_fields');

                $validator->errors()->add(
                    'compliance_profile',
                    is_string($message) ? $message : 'At least one field is required.',
                );
            }
        });
    }

    /**
     * Nombres legibles para los mensajes, en el idioma negociado.
     *
     * Sin esto, un `422` diria «El campo min_rest_hours debe ser un numero
     * entero», que es el nombre de la columna y no lo que el administrador ve en
     * la pantalla.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (ComplianceProfileField::cases() as $field) {
            $label = __('compliance-profile.attributes.'.$field->value);

            if (is_string($label)) {
                $attributes[$field->value] = $label;
                $attributes[$field->value.'.*'] = $label;
            }
        }

        return $attributes;
    }

    public function toCommand(): UpdateComplianceProfileCommand
    {
        $values = [];

        foreach ($this->submittedFields() as $field) {
            /** @var mixed $value */
            $value = $this->input($field->value);

            $values[$field->value] = $value;
        }

        return new UpdateComplianceProfileCommand(
            values: $values,
            actorUserId: $this->actorUserId(),
        );
    }

    /**
     * Los campos del catalogo que la peticion trae, en el orden del catalogo.
     *
     * @return list<ComplianceProfileField>
     */
    private function submittedFields(): array
    {
        $submitted = [];

        foreach (ComplianceProfileField::cases() as $field) {
            if ($this->has($field->value)) {
                $submitted[] = $field;
            }
        }

        return $submitted;
    }

    /**
     * Las reglas de un campo, derivadas de su declaracion.
     *
     * `sometimes` en todas: es un `PATCH` y lo que no viaja no cambia.
     *
     * @return array<string, list<mixed>>
     */
    private function rulesFor(ComplianceProfileField $field): array
    {
        return match ($field->type()) {
            ComplianceProfileFieldType::Integer => [$field->value => [
                'sometimes',
                // `integer` de Laravel acepta «12» entrecomillado porque valida
                // con `filter_var`. El dominio no lo acepta —y hace bien: un JSON
                // escrito a mano con comillas convertiria un error en un umbral
                // legal silenciosamente distinto del que se cree haber puesto—,
                // asi que la comprobacion estricta va aqui tambien para que el
                // `422` señale el campo.
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value)) {
                        $fail('compliance-profile.strict_integer')->translate();
                    }
                },
                'integer',
                'min:'.$field->minimum(),
                'max:'.$field->maximum(),
            ]],
            ComplianceProfileFieldType::Text => [$field->value => [
                'sometimes',
                'string',
                'filled',
                'max:'.$field->maximumLength(),
            ]],
            ComplianceProfileFieldType::DateList => [
                // La lista VACIA es valida y significa «este centro no tiene
                // festivos cargados», que es el valor con el que se entrega el
                // perfil. `array` sin `min:1`, a proposito.
                $field->value => ['sometimes', 'array', 'max:400'],
                $field->value.'.*' => ['string', 'date_format:Y-m-d', 'distinct'],
            ],
        };
    }

    private function actorUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
