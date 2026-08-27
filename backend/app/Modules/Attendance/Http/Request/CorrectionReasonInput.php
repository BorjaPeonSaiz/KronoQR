<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que las tres peticiones de correccion tienen en comun: el motivo del Anexo
 * C y el usuario que firma (RF-PA-04, RN-13).
 *
 * **Las reglas de validacion no sustituyen al objeto de valor, lo anticipan.**
 * `CorrectionReason` ya hace inconstruible un `OTROS` sin explicacion de veinte
 * caracteres, y la restriccion de `shift_corrections` lo prohibe tambien en la
 * base de datos. Que ademas se valide aqui no es redundancia inutil: es la
 * diferencia entre un `422` con el campo señalado —que quien rellena el
 * formulario puede arreglar— y un `500` con una excepcion de dominio, que no le
 * dice nada.
 *
 * **El autor no se declara, se toma de la sesion.** RN-13 exige autor, y ese
 * autor es quien tiene la sesion abierta. Aceptarlo en el cuerpo permitiria
 * firmar una correccion a nombre de otra persona, que es exactamente lo que un
 * registro con valor probatorio no puede admitir.
 *
 * @phpstan-require-extends FormRequest
 */
trait CorrectionReasonInput
{
    /**
     * Reglas del motivo, comunes a las tres peticiones.
     *
     * @return array<string, list<string>>
     */
    private function reasonRules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'in:'.implode(',', self::reasonCodes())],
            // `nullable` y no `sometimes`: el contrato admite `null` explicito, y
            // un texto en blanco es lo mismo que no haberlo enviado. La longitud
            // minima de `OTROS` se comprueba aparte, porque depende del codigo.
            'reason_text' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * La regla condicional del Anexo C, dicha donde el usuario la puede
     * arreglar.
     *
     * Se engancha con `after` y no como regla `required_if` porque hay que medir
     * el texto **ya recortado**: `required_if` daria por bueno un campo con
     * veinte espacios, y veinte espacios no explican una rectificacion del
     * registro horario de nadie.
     */
    private function validateExplanation(Validator $validator): void
    {
        if ($this->reasonCodeInput() !== CorrectionReasonCode::OTROS->value) {
            return;
        }

        $text = $this->reasonTextInput();

        if ($text !== null && mb_strlen($text) >= CorrectionReason::MINIMUM_EXPLANATION_LENGTH) {
            return;
        }

        $validator->errors()->add(
            'reason_text',
            'El motivo OTROS obliga a una explicacion de al menos '
            .CorrectionReason::MINIMUM_EXPLANATION_LENGTH.' caracteres.',
        );
    }

    /**
     * El motivo ya construido. Si llegase aqui invalido, el objeto de valor lo
     * rechazaria; la validacion de arriba existe para que no llegue.
     */
    private function correctionReason(): CorrectionReason
    {
        return CorrectionReason::fromCode($this->reasonCodeInput(), $this->reasonTextInput());
    }

    /**
     * `users.id` de quien firma, tomado de la sesion autenticada.
     *
     * Cero es imposible en la practica —estas rutas van tras `auth:sanctum`— y
     * si ocurriera, el comando de aplicacion lo rechaza: RN-13 no admite una
     * correccion sin autor, y prefiere romper a escribir «lo hizo el sistema».
     */
    private function performedByUserId(): int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : 0;
    }

    private function reasonCodeInput(): string
    {
        $code = $this->input('reason_code');

        return \is_string($code) ? $code : '';
    }

    private function reasonTextInput(): ?string
    {
        $text = $this->input('reason_text');

        if (! \is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Los nueve codigos del Anexo C, derivados del enum del dominio.
     *
     * Se derivan y no se escriben: una lista repetida en la validacion y en el
     * enum divergiria el dia que el Anexo C creciera, y el sintoma seria un
     * motivo que el formulario acepta y el dominio rechaza con un `500`.
     *
     * @return list<string>
     */
    private static function reasonCodes(): array
    {
        return array_map(
            static fn (CorrectionReasonCode $code): string => $code->value,
            CorrectionReasonCode::cases(),
        );
    }
}
