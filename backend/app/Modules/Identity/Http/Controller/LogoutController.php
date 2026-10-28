<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\LogOutHandler;
use App\Modules\Identity\Http\Policy\SessionPolicy;
use App\Modules\Identity\Http\Request\LogoutRequest;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/auth/logout` — cierre de sesion de una cuenta de gestion.
 *
 * Revoca **el token de esta llamada** y ninguno mas: cerrar sesion en el
 * portatil no puede echar a la misma persona de la tablet donde estaba
 * trabajando.
 *
 * Devuelve `204` sin cuerpo. No hay nada que contar y un cuerpo vacio con
 * estructura solo daria de que hablar al cliente.
 *
 * **Quien puede llamarla lo decide {@see SessionPolicy}** en la peticion: una
 * cuenta de gestion o un acceso de soporte (regla dura 18). Un quiosco o una
 * sesion del portal reciben `403` antes de llegar aqui (ADR-039): ningun cliente
 * oficial cierra sesion por esta ruta con esos tokens —el portal tiene
 * `POST /api/v1/me/logout`— y con el token robado de una tablet bastaria una
 * llamada para dejarla sin servicio sin asiento. Por eso aqui el canal es
 * siempre `management`; lo que se hace con el —el asiento y su actor— lo decide
 * el caso de uso y el escritor de auditoria, no este fichero.
 *
 * ## Un acceso de soporte NO cierra sesion aqui (RF-PD-11, ADR-020)
 *
 * Responde `204` y **no hace nada**. Tratarlo como una sesion de gestion
 * tendria tres consecuencias malas a la vez: le borraria el token **sin marcar
 * `revoked_at`**, dejando una concesion que el panel enseña «activa» y que ya
 * no funciona —la peor de las dos incoherencias—; escribiria `auth.logout` como
 * si el fabricante fuera una cuenta del cliente; y daria una segunda via para
 * retirar un acceso, cuando `Product\Http\Policy\SupportGrantPolicy` niega esa
 * potestad al fabricante a proposito —nombrada en prosa: este modulo no conoce
 * las policies de `Product`— porque quien recibe el acceso no decide cuando se
 * cierra.
 *
 * **No se delega en la revocacion real**, y es la parte deliberada: un token de
 * soporte no es una sesion que se abra y se cierre, es una credencial con
 * caducidad fija que **el cliente** controla. Se retira sola al expirar, o la
 * retira el cliente desde su panel o con `support:revoke`. Que el fabricante
 * pueda cerrarse la puerta antes de tiempo parece inofensivo y no lo es: seria
 * la unica accion del ciclo de vida de una concesion que el fabricante decide,
 * y ademas le permitiria cerrar su propio rastro cuando le conviniera.
 *
 * `204` y no `403` porque no hay nada que denegar: la peticion es valida y su
 * efecto —que no queda sesion abierta que cerrar— ya se cumple.
 */
final class LogoutController extends Controller
{
    public function __invoke(LogoutRequest $request, LogOutHandler $handler): Response
    {
        $user = $request->user();

        // Un acceso de soporte no tiene sesion que cerrar. Ver el docblock: ni
        // se le borra el token ni se audita como si fuera una cuenta del cliente.
        if ($user instanceof ManagementActor && $user->isSupportActor()) {
            return response()->noContent();
        }

        $token = $user?->currentAccessToken();

        if ($user !== null && $token instanceof PersonalAccessToken) {
            $uuid = $user->getAttribute('uuid');

            $handler->handle(
                $token->id,
                AuthChannel::MANAGEMENT,
                is_string($uuid) && $uuid !== '' ? $uuid : null,
            );
        }

        return response()->noContent();
    }
}
