<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\VerifyTwoFactorCommand;
use App\Modules\Identity\Http\Policy\TwoFactorPolicy;
use App\Modules\Identity\Http\Support\PendingTwoFactorSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El codigo del autenticador, para `/auth/2fa/verify` y `/auth/2fa/confirm`.
 *
 * **Un solo `FormRequest` para los dos endpoints** porque reciben exactamente lo
 * mismo y lo autorizan igual; lo que cambia es el caso de uso que lo atiende. Dos
 * clases identicas serian dos sitios donde arreglar la misma errata. La regla dura
 * 18 se cumple igual: cada ruta declara su metodo de policy y las dos tienen su
 * prueba negativa.
 *
 * **`pattern` de seis digitos aqui si, al contrario que en el PIN del portal.** La
 * forma de un TOTP es publica —RFC 6238, y el propio autenticador la enseña—, asi
 * que describirla no revela nada; en el portal, en cambio, un `pattern` haria que
 * un codigo malformado devolviera `400` y uno bien formado pero inexistente `401`,
 * y esa diferencia si es un oraculo (regla dura 17).
 *
 * **La clave del contador y el sujeto no salen del cuerpo.** El sujeto es el
 * dueño del token pendiente y el contador se lleva por cuenta y origen: si
 * viajaran en la peticion, cualquiera podria gastar el cupo de otra persona o
 * presentar un codigo a nombre ajeno.
 */
final class TwoFactorCodeRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        $policy = new TwoFactorPolicy;

        return $this->routeIs('auth.2fa.confirm')
            ? $policy->confirm($this->user())
            : $policy->verify($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }

    /**
     * Algunos autenticadores enseñan el codigo en dos grupos de tres, y quien lo
     * copia se lleva el espacio. Quitarlo aqui evita un `422` que la persona no
     * entiende y que no protege de nada.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (\is_string($code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $code) ?? $code]);
        }
    }

    public function toCommand(): VerifyTwoFactorCommand
    {
        $session = PendingTwoFactorSession::of($this);

        return new VerifyTwoFactorCommand(
            userUuid: $session->userUuid,
            code: $this->string('code')->value(),
            deviceName: $session->deviceName,
            challengeTokenId: $session->tokenId,
            // Por cuenta y por origen, igual que el contador de la contrasena:
            // solo por cuenta bastaria con rotar origenes, y solo por origen
            // dejaria a toda una oficina compartiendo cupo.
            throttleKey: '2fa|'.$session->userUuid.'|'.(string) $this->ip(),
        );
    }
}
