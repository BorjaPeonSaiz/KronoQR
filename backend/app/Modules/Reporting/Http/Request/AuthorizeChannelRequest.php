<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * La peticion de firma de una suscripcion a un canal privado
 * (`POST /api/v1/broadcasting/auth`, ADR-011, RF-PA-01).
 *
 * ## Por que existe, si Laravel ya trae el controlador
 *
 * El `BroadcastController` del framework no valida nada: pasa `socket_id` y
 * `channel_name` tal cual a la libreria de Pusher. Un `socket_id` que no tiene la
 * forma `123.456` —o que falta— revienta dentro de ella como `500`, y cada uno
 * deja una fila en `error_events` por una peticion mal formada (B3, bloque 12
 * de la 2.3.0). Aqui se rechaza antes con `422`, como cualquier otra ruta.
 *
 * Y rechaza lo desconocido: el controlador del framework responde **JSONP** si
 * llega un `callback`, una forma de respuesta que el contrato no declara y que
 * ninguna aplicacion cliente usa.
 *
 * ## La policy, ademas del ambito
 *
 * El AMBITO lo exige el middleware de la ruta (`attendance:read`); aqui se
 * pregunta la misma habilidad que `GET /api/v1/attendance/live`
 * (`view` sobre {@see PresenceBoard}), porque suscribirse al canal es otra forma
 * de leer lo mismo (regla dura 18). El alcance por departamento y la licencia los
 * vuelve a comprobar el callback de cada canal en `routes/channels.php`.
 */
final class AuthorizeChannelRequest extends FormRequest
{
    use RejectsUnknownInput;

    /** El canal mas largo es `private-presence.department.<id>`; esto sobra. */
    public const int MAX_CHANNEL_NAME_LENGTH = 200;

    public function authorize(): bool
    {
        return Gate::allows('view', PresenceBoard::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // La forma que exige el protocolo de Pusher y que Reverb asigna.
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:'.self::MAX_CHANNEL_NAME_LENGTH],
        ];
    }
}
