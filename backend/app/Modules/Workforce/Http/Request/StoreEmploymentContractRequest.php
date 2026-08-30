<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\RegisterEmploymentContractCommand;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Alta del contrato de una persona (**RF-GP-02**).
 *
 * **No admite `valid_to`, y es deliberado.** Un contrato se registra abierto y
 * lo cierra el siguiente: dejar escribir la fecha de fin permitiria crear un
 * hueco de dias sin contrato vigente sin que nada avisara, y el informe de
 * RF-IN-03 los contaria como «sin contrato» sin que nadie entienda por que.
 * Cuando la relacion laboral termina, lo que hay es una baja (RF-GP-03), que
 * tiene su propio endpoint y su propio hecho auditado.
 *
 * **Tampoco admite `employee_uuid` en el cuerpo**: va en la ruta. Dos sitios
 * para lo mismo es una discrepancia esperando a ocurrir.
 *
 * **`weekly_hours` se valida con techo y suelo aqui ademas de en el dominio y en
 * el esquema.** No es duplicacion inutil: esta da un `422` con el campo
 * señalado, la del dominio da un mensaje con significado a cualquier otro camino
 * y la del `CHECK` protege de lo que no pasa por PHP.
 */
final class StoreEmploymentContractRequest extends FormRequest
{
    use RejectsUnknownInput;

    /** Horas de una semana. No es un umbral legal: es un absurdo aritmetico. */
    public const int MAX_WEEKLY_HOURS = 168;

    /** Techo del `numeric(7,2)` de la columna. */
    public const int MAX_ANNUAL_HOURS = 99999;

    public function authorize(): bool
    {
        return Gate::allows('create', EmploymentContract::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // `numeric` y no `integer`: 37,5 h semanales es el caso normal en
            // hosteleria. `min` estricto lo pone `gt`, porque un contrato de
            // cero horas no describe ninguna jornada.
            'weekly_hours' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_WEEKLY_HOURS],
            'annual_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:'.self::MAX_ANNUAL_HOURS],
            // Los casos salen del enum y no de una lista escrita a mano: un
            // valor nuevo entraria aqui solo, y una lista copiada se quedaria
            // atras sin que nada fallara.
            'schedule_type' => ['required', 'string', 'in:'.implode(',', ScheduleType::names())],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function toCommand(string $employeeUuid): RegisterEmploymentContractCommand
    {
        return new RegisterEmploymentContractCommand(
            employeeUuid: $employeeUuid,
            // `numeric` ya lo ha validado; el `?? 0.0` no es un valor por
            // omision, es lo que hace falta para que el tipo sea `float` sin un
            // `assert`. Un cero llegaria al dominio y lo rechazaria en voz alta.
            weeklyHours: $this->hours('weekly_hours') ?? 0.0,
            annualHours: $this->hours('annual_hours'),
            scheduleType: ScheduleType::from($this->string('schedule_type')->value()),
            validFrom: $this->string('valid_from')->value(),
            registeredByUserId: $this->managementUserId(),
        );
    }

    /**
     * Un campo de horas ya validado como `numeric`, o `null` si no vino.
     *
     * `37,5` llega como cadena en una peticion HTTP; la conversion vive aqui
     * —una sola vez para los dos campos— y no en el comando, que habla en
     * `float` porque es lo que el dominio necesita.
     */
    private function hours(string $field): ?float
    {
        $value = $this->input($field);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * La clave interna de quien registra, para dejarla en la fila.
     *
     * El asiento de `audit_log` ya dice quien fue —con su cadena de hash—, pero
     * la columna deja el dato legible en la propia tabla sin cruzar el trail
     * para pintar la ficha. `null` cuando quien llama no es una cuenta de
     * gestion, que no deberia poder ocurrir porque la policy lo impide: es la
     * respuesta prudente, no un caso esperado.
     */
    private function managementUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
