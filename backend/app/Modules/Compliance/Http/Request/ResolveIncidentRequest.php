<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Compliance\Application\Command\ResolveIncidentCommand;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/incidents/{id}/resolve` — cerrar una incidencia con su nota
 * (**RF-PA-05**, RN-13).
 *
 * ## La nota es obligatoria, y esa decision se toma aqui
 *
 * El esquema de `incidents` deja `resolution_note` anulable porque una columna
 * describe lo que la tabla puede contener, no lo que el producto exige: hay filas
 * historicas concebibles sin nota y una migracion de datos no puede inventarla.
 * Lo que RF-PA-05 pide es un **flujo de resolucion con nota**, y ese flujo pasa
 * entero por esta clase y por {@see Incident::resolvedBy()}. Aqui es un `422` con
 * el campo señalado —que quien rellena el formulario puede arreglar— y alli una
 * excepcion de dominio, que es la red por si algun dia hay un segundo camino.
 *
 * **Tambien al descartar.** «Se ha mirado y no habia nada» es exactamente lo que
 * hay que poder explicar seis meses despues, cuando alguien pregunte por que esa
 * jornada de trece horas no se corrigio.
 *
 * ## Se mide recortada
 *
 * El minimo se comprueba sobre el texto **ya recortado**, con `after` y no con
 * `min:3`: aquello daria por buena una nota de tres espacios, y tres espacios no
 * explican nada. Es el mismo criterio que la explicacion del motivo `OTROS` en
 * las correcciones.
 *
 * ## El autor no se declara, se toma de la sesion
 *
 * RN-13 exige autor, y ese autor es quien tiene la sesion abierta. Aceptarlo en
 * el cuerpo permitiria firmar la resolucion a nombre de otra persona, que es lo
 * que un registro con valor probatorio no puede admitir. `RejectsUnknownInput`
 * ademas rechaza cualquier intento de mandarlo.
 */
final class ResolveIncidentRequest extends FormRequest
{
    /**
     * Tres caracteres. No es una regla de negocio ni un umbral legal: es el
     * minimo que distingue una nota de una pulsacion accidental. Lo que exige el
     * requisito es que **haya** nota.
     */
    public const int MINIMUM_NOTE_LENGTH = 3;

    /**
     * Mil. La nota va integra al payload de `audit_log`, que se conserva cuatro
     * años (RL-02) y viaja entera en la exportacion legal: un techo aqui es lo
     * que impide que una pegada accidental de medio megabyte entre en la cadena
     * de hash.
     */
    public const int MAXIMUM_NOTE_LENGTH = 1000;

    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function authorize(): bool
    {
        return Gate::allows('resolve', Incident::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // `resolved` o `dismissed`, nunca `open`: reabrir no es un
            // desenlace. La lista sale del enum menos el estado inicial, para
            // que un estado nuevo no entre aqui por descuido.
            'outcome' => ['required', 'string', 'in:'.implode(',', self::outcomes())],
            'note' => ['required', 'string', 'max:'.self::MAXIMUM_NOTE_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            if (mb_strlen($this->note()) >= self::MINIMUM_NOTE_LENGTH) {
                return;
            }

            $validator->errors()->add(
                'note',
                'Cerrar una incidencia exige explicar por que, con al menos '
                .self::MINIMUM_NOTE_LENGTH.' caracteres. Tambien al descartarla.',
            );
        });
    }

    public function toCommand(int $incidentId, ScopeGuard $scope): ResolveIncidentCommand
    {
        return new ResolveIncidentCommand(
            incidentId: $incidentId,
            outcome: IncidentStatus::from($this->string('outcome')->value()),
            note: $this->note(),
            resolvedByUserId: $this->resolvedByUserId(),
            scope: $scope->scopeOf($this->user()),
        );
    }

    /**
     * Los dos desenlaces finales: el catalogo entero **menos** `open`.
     *
     * Derivado y no escrito a mano para que un estado nuevo del enum no se cuele
     * aqui sin que nadie lo decida.
     *
     * @return list<string>
     */
    private static function outcomes(): array
    {
        return array_values(array_map(
            static fn (IncidentStatus $status): string => $status->value,
            array_filter(
                IncidentStatus::cases(),
                static fn (IncidentStatus $status): bool => ! $status->isOpen(),
            ),
        ));
    }

    private function note(): string
    {
        return trim($this->string('note')->value());
    }

    /**
     * `users.id` de quien firma, tomado de la sesion autenticada.
     *
     * Cero es imposible en la practica —esta ruta va tras `auth:sanctum`— y si
     * ocurriera, el dominio lo rechaza: RN-13 no admite una intervencion sin
     * autor, y prefiere romper a escribir «lo hizo el sistema».
     */
    private function resolvedByUserId(): int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : 0;
    }
}
