<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Reporting\Http\Request\AuthorizeChannelRequest;
use Illuminate\Contracts\Broadcasting\Factory as Broadcasting;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `POST /api/v1/broadcasting/auth`: la firma de la suscripcion a un canal de
 * presencia (ADR-011, RF-PA-01).
 *
 * Sustituye a la ruta que registraba `withBroadcasting()`, que aceptaba `GET` y
 * `POST`, no validaba la peticion y respondia JSONP con un `callback` (B3 y
 * R6-BD-02 de la verificacion de la 2.2.0). Quien decide si se firma sigue siendo
 * el difusor: ejecuta el callback del canal de `routes/channels.php` y responde
 * `403` si dice que no.
 *
 * ## Solo los dos canales que existen, escritos tal cual
 *
 * El difusor de Pusher **normaliza** el nombre antes de buscar su callback:
 * `private-encrypted-presence.all` o `presence-presence.all` acaban en el
 * callback de `presence.all` y se firmarian como canales de otro tipo. Y el
 * patron de `Broadcast::channel()` no escapa el punto, asi que
 * `private-presenceXall` tambien encajaba. Aqui solo pasa
 * `private-presence.all` o `private-presence.department.<digitos>`; cualquier
 * otro nombre es el mismo `403` que un canal ajeno, **antes** de llegar al
 * difusor y sin asiento: no es un acceso a datos de nadie, es un nombre que no
 * existe (bloque 12 de la 2.3.0).
 */
final class ChannelAuthorizationController extends Controller
{
    private const string WIRE_PREFIX = 'private-';

    public function __invoke(AuthorizeChannelRequest $request, Broadcasting $broadcasting): JsonResponse
    {
        if (preg_match(self::channelPattern(), $request->string('channel_name')->value()) !== 1) {
            throw new AccessDeniedHttpException;
        }

        // El difusor ACTIVO en esta peticion, no uno resuelto al arrancar: es el
        // que tiene registrados los canales de `routes/channels.php`.
        $signature = $broadcasting->connection()->auth($request);

        return $signature instanceof JsonResponse ? $signature : new JsonResponse($signature);
    }

    /**
     * `\Aprivate-presence\.(all|department\.[0-9]+)\z`, a partir de las
     * constantes de {@see PresenceChannels} para que un canal renombrado no se
     * quede fuera sin que nada falle.
     */
    public static function channelPattern(): string
    {
        return '/\A'.preg_quote(self::WIRE_PREFIX, '/')
            .'(?:'.preg_quote(PresenceChannels::ALL, '/')
            .'|'.preg_quote(PresenceChannels::DEPARTMENT_PREFIX, '/').'[0-9]+)\z/';
    }
}
