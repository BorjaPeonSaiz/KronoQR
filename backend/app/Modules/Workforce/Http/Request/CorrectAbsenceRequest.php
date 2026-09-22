<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CorrectAbsenceCommand;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Correccion de una ausencia (**RF-GP-04**, **RN-13**).
 *
 * **Todo opcional menos `reason`.** Los campos omitidos conservan su valor: una
 * correccion que obligara a reenviar el objeto entero convertiria cualquier
 * cambio parcial en una oportunidad de pisar sin querer lo que no se tocaba.
 *
 * **`note` admite `null` explicito.** Enviarlo la borra; omitir el campo la deja
 * como esta. La distincion se resuelve con `has()` y no con el valor, que es la
 * unica forma de expresarla en un `PATCH`.
 *
 * **No admite `employee_uuid`.** Corregir a quien pertenece una ausencia no es
 * corregirla: es anular esta y registrar otra, que es lo que deja el rastro
 * correcto de que hubo dos personas implicadas.
 *
 * **Las fechas no se validan una contra otra aqui, y es deliberado**: una
 * correccion puede mandar solo `ends_on`, y `after_or_equal:starts_on` sobre un
 * campo ausente no compara nada. La invariante la hacen cumplir el modelo de
 * dominio —con la fecha que de verdad queda— y el `CHECK` de la columna.
 */
final class CorrectAbsenceRequest extends FormRequest
{
    /** Suelo y techo de los dos motivos libres del producto. */
    public const int MIN_REASON_LENGTH = 3;

    public const int MAX_REASON_LENGTH = 500;

    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('correct', Absence::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'in:'.implode(',', AbsenceType::names())],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StoreAbsenceRequest::MAX_NOTE_LENGTH],
            // Obligatorio: es lo que RN-13 exige conservar. Sin catalogo, porque
            // no hay causas tipificadas que defender ante Inspeccion como en las
            // correcciones del registro horario.
            'reason' => [
                'required',
                'string',
                'min:'.self::MIN_REASON_LENGTH,
                'max:'.self::MAX_REASON_LENGTH,
            ],
        ];
    }

    public function toCommand(string $absenceUuid): CorrectAbsenceCommand
    {
        return new CorrectAbsenceCommand(
            absenceUuid: $absenceUuid,
            reason: trim($this->string('reason')->value()),
            type: $this->has('type') ? AbsenceType::from($this->string('type')->value()) : null,
            startsOn: $this->has('starts_on') ? $this->string('starts_on')->value() : null,
            endsOn: $this->has('ends_on') ? $this->string('ends_on')->value() : null,
            note: $this->correctedNote(),
            // `has()` y no el valor: es lo que distingue «borrala» de «no la
            // toques», y sin esta bandera vaciar una nota seria imposible.
            noteGiven: $this->has('note'),
            correctedByUserId: $this->managementUserId(),
        );
    }

    private function correctedNote(): ?string
    {
        if (! $this->has('note')) {
            return null;
        }

        $note = trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    private function managementUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
