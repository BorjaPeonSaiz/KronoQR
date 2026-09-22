<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\RegisterAbsenceCommand;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Alta de una ausencia (**RF-GP-04**).
 *
 * **`employee_uuid` va en el cuerpo y no en la ruta**, al contrario que en los
 * contratos, porque `/absences` es un recurso de primer nivel (Anexo B del doc
 * 01) y se registra desde una pantalla transversal con buscador de persona. Por
 * eso `exists:` lo comprueba aqui y produce un `422` sobre el campo: hay algo
 * que corregir en el formulario, no una ruta que no lleva a ninguna parte.
 *
 * **No admite `status`, `version` ni nada del encadenado** (`RejectsUnknownInput`
 * los rechaza): los escribe el servidor. Dejarlos entrar permitiria registrar una
 * ausencia ya anulada, que es un estado que nadie sabria interpretar.
 *
 * **La nota se valida con techo aqui, en el dominio y en el esquema.** No es
 * duplicacion inutil: esta da un `422` con el campo señalado, la del dominio da
 * un mensaje con significado a cualquier otro camino y el `CHECK` protege de lo
 * que no pasa por PHP.
 */
final class StoreAbsenceRequest extends FormRequest
{
    use RejectsUnknownInput;

    /**
     * El techo de la nota, **derivado del dominio y no escrito otra vez**.
     *
     * El alias se queda porque las reglas de validacion se leen mejor con un
     * nombre del borde, pero el numero vive en {@see Absence::MAX_NOTE_LENGTH}:
     * la carga por fichero no pasa por aqui y necesita el mismo techo, y dos
     * copias del numero eran dos oportunidades de que se separaran.
     */
    public const int MAX_NOTE_LENGTH = Absence::MAX_NOTE_LENGTH;

    public function authorize(): bool
    {
        return Gate::allows('create', Absence::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'uuid', 'exists:employees,uuid'],
            // Los casos salen del enum, no de una lista escrita a mano.
            'type' => ['required', 'string', 'in:'.implode(',', AbsenceType::names())],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            // `after_or_equal` y no `after`: una ausencia de un solo dia tiene
            // los dos extremos en el mismo, que es el caso mas frecuente.
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'La ausencia no puede terminar antes de empezar.',
        ];
    }

    /**
     * Validacion condicional: `other` exige nota.
     *
     * Se escribe con `after()` y no con `required_if` porque el mensaje tiene
     * que explicar **por que** —«ese tipo necesita una nota que lo explique»— y
     * no «el campo nota es obligatorio», que deja a quien lo lee sin saber que
     * cambiando el tipo tambien se arregla.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = AbsenceType::tryFrom($this->string('type')->value());

            if ($type?->requiresNote() === true && trim($this->string('note')->value()) === '') {
                $validator->errors()->add(
                    'note',
                    'Ese tipo de ausencia necesita una nota que lo explique. No escribas un diagnostico.',
                );
            }
        });
    }

    public function toCommand(): RegisterAbsenceCommand
    {
        return new RegisterAbsenceCommand(
            employeeUuid: $this->string('employee_uuid')->value(),
            type: AbsenceType::from($this->string('type')->value()),
            startsOn: $this->string('starts_on')->value(),
            endsOn: $this->string('ends_on')->value(),
            note: $this->optionalNote(),
            registeredByUserId: $this->managementUserId(),
        );
    }

    private function optionalNote(): ?string
    {
        $note = trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    /**
     * La clave interna de quien registra, para dejarla en la fila.
     *
     * El asiento de `audit_log` ya dice quien fue —con su cadena de hash—, pero
     * la columna deja el dato legible en la propia tabla sin cruzar el trail para
     * pintar la pantalla. `null` cuando quien llama no es una cuenta de gestion,
     * que no deberia poder ocurrir porque la policy lo impide: es la respuesta
     * prudente, no un caso esperado.
     */
    private function managementUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
