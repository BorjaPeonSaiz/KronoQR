<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Application\Command\GrantSupportAccessCommand;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * `POST /api/v1/support/grants` (contrato `GrantSupportAccessRequest`).
 *
 * ## `reason` es obligatorio, y es la unica validacion que no es de forma
 *
 * El contrato lo marca `required` y el requisito tambien (RF-PD-11). No es
 * burocracia: **la concesion existe para un incidente concreto**, ese texto va
 * tal cual al asiento de auditoria, y usar el acceso para otra cosa es el abuso
 * que este mecanismo existe para hacer visible (ADR-020, §8.1, `T1199`). Sin
 * motivo, el trail diria «entro alguien» y no serviria para nada.
 *
 * ## El maximo de horas sale de la configuracion, no de una constante
 *
 * `PRODUCT_SUPPORT_GRANT_MAX_HOURS` (72 de serie, regla dura 13). Se resuelve
 * **aqui, en el borde**, y se le pasa ya resuelto al dominio (regla dura 14):
 * asi una prueba puede fijar un tope de una hora sin tocar el `.env` de nadie, y
 * un cliente con una politica mas dura lo baja sin tocar el repositorio.
 *
 * El contrato declara `maximum: 72` porque un esquema OpenAPI no puede leer
 * configuracion; si un cliente lo bajara, la respuesta a un valor entre su tope
 * y 72 sigue siendo un `422` correcto — mas estricta que el contrato, nunca mas
 * laxa.
 *
 * ## Ni el autor ni el token se declaran
 *
 * El autor sale de la sesion: aceptarlo en el cuerpo permitiria autorizar un
 * acceso del fabricante a nombre de otra persona. Y el token no se puede pedir:
 * lo genera el servidor y sale una sola vez.
 */
final class GrantSupportAccessRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('grant', SupportGrant::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'min:'.SupportGrant::REASON_MIN_LENGTH,
                'max:'.SupportGrant::REASON_MAX_LENGTH,
            ],
            'scope' => ['sometimes', 'string', 'in:'.implode(',', SupportScope::names())],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:'.self::maximumHours()],
        ];
    }

    public function toCommand(): GrantSupportAccessCommand
    {
        /** @var string $reason */
        $reason = $this->validated('reason');

        /** @var string|null $scope */
        $scope = $this->validated('scope');

        /** @var int|null $hours */
        $hours = $this->validated('hours');

        return new GrantSupportAccessCommand(
            reason: $reason,
            // El alcance de serie es el mas estrecho: quien concede deprisa, en
            // mitad de una incidencia, acaba con el minimo y no con el maximo.
            scope: $scope === null ? SupportScope::default() : SupportScope::from($scope),
            hours: $hours ?? self::defaultHours(),
            grantedByUserId: $this->actorUserId(),
        );
    }

    public static function maximumHours(): int
    {
        return max(1, Config::integer('product.support_grant_max_hours', 72));
    }

    private static function defaultHours(): int
    {
        return max(1, min(self::maximumHours(), Config::integer('product.support_grant_default_hours', 24)));
    }

    /**
     * La cuenta que autoriza, tomada de la sesion.
     *
     * **Nunca puede faltar**: la ruta va detras de `auth:sanctum` y la policy ya
     * ha exigido que sea un `admin` que no es un actor de soporte. Si aun asi no
     * hubiera identificador, lo correcto es romper y no escribir una concesion
     * sin autor: una autorizacion sin firmante no acredita nada ante el art. 28
     * RGPD.
     */
    private function actorUserId(): int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        if (! is_numeric($identifier)) {
            throw new RuntimeException('Una concesion de soporte necesita la cuenta que la autoriza.');
        }

        return (int) $identifier;
    }
}
