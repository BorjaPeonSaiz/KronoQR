<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\EnrolTwoFactorCommand;
use App\Modules\Identity\Http\Policy\TwoFactorPolicy;
use App\Modules\Identity\Http\Support\PendingTwoFactorSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/2fa/enrol` — un `FormRequest` para un endpoint **sin
 * cuerpo**.
 *
 * Existe por lo mismo que el del padron del quiosco y ninguno de los dos motivos
 * es la validacion:
 *
 * 1. **La policy** (regla dura 18), invocada por su nombre porque aqui no hay
 *    modelo de dominio sobre el que registrar un `Gate`.
 * 2. **Rechazar lo que no se conoce.** El alta no admite ningun parametro, y en
 *    particular **no admite un `uuid`**: la cuenta sale del token pendiente. Un
 *    `{"user_uuid": "..."}` ignorado en silencio dejaria a quien lo envia
 *    convencido de haber generado el secreto de otra persona.
 */
final class TwoFactorEnrolmentRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return (new TwoFactorPolicy)->enrol($this->user());
    }

    /**
     * Ningun parametro. La lista vacia no es un descuido: es lo que hace que
     * `RejectsUnknownInput` rechace cualquier cosa que llegue.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(): EnrolTwoFactorCommand
    {
        $session = PendingTwoFactorSession::of($this);

        return new EnrolTwoFactorCommand(
            userUuid: $session->userUuid,
            // LA MISMA CLAVE QUE `/verify` Y `/confirm`, letra por letra: el alta
            // comparte el bloqueo por intentos de codigo. Con una clave propia,
            // pedir un secreto nuevo seria la forma de salir del bloqueo.
            //
            // Por cuenta y sin la IP, por lo mismo que en `TwoFactorCodeRequest`:
            // un contador de fallos que se reinicia cambiando de origen no cuenta
            // fallos.
            throttleKey: '2fa|'.$session->userUuid,
        );
    }
}
