<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\LogOutHandler;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/auth/logout` — cierre de sesion.
 *
 * Revoca **el token de esta llamada** y ninguno mas: cerrar sesion en el
 * portatil no puede echar a la misma persona de la tablet donde estaba
 * trabajando.
 *
 * Devuelve `204` sin cuerpo. No hay nada que contar y un cuerpo vacio con
 * estructura solo daria de que hablar al cliente.
 *
 * **Aqui se resuelve el canal y el sujeto, y nada mas.** Quien cierra sesion es
 * o una cuenta de gestion o un empleado del portal —el endpoint acepta los dos
 * tokens—, y esa distincion solo se puede hacer donde esta la peticion. Lo que
 * se hace con ella —si deja asiento y con que actor— lo decide el caso de uso y
 * el escritor de auditoria, no este fichero.
 *
 * El empleado se reconoce por descarte y no por su clase: `Identity` no puede
 * importar el modelo `Employee` de `Workforce` (doc 02 §1.6, verificado por
 * Deptrac), que es el mismo criterio que ya usa `IdentityServiceProvider` con la
 * tabla del `tokenable`.
 *
 * ## Un acceso de soporte NO cierra sesion aqui (RF-PD-11, ADR-020, tarea 5.9)
 *
 * Responde `204` y **no hace nada**. El descarte de arriba lo habria tratado
 * como una sesion de portal, con tres consecuencias malas a la vez: le habria
 * borrado el token **sin marcar `revoked_at`**, dejando una concesion que el
 * panel enseña «activa» y que ya no funciona —la peor de las dos
 * incoherencias—; habria escrito `auth.logout` en el canal del **portal del
 * empleado**, ensuciando el trail de acceso de la plantilla con la actividad
 * del fabricante; y habria dado una segunda via para retirar un acceso, cuando
 * `Product\Http\Policy\SupportGrantPolicy` niega esa potestad
 * al fabricante a proposito —nombrada en prosa: este modulo no conoce las
 * policies de `Product`— porque quien recibe el acceso no decide cuando se
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
    /**
     * La tabla del `tokenable` de una sesion de gestion. Se compara la tabla y
     * no la clase por lo mismo que hacen `IdentityServiceProvider` y
     * `Compliance\Infrastructure\Audit\CurrentAuditContext`: el otro `tokenable`
     * posible es `Employee`, de `Workforce`, y este modulo no puede importarlo.
     */
    private const string MANAGEMENT_TABLE = 'users';

    public function __invoke(Request $request, LogOutHandler $handler): Response
    {
        $user = $request->user();

        // Un acceso de soporte no tiene sesion que cerrar. Ver el docblock: ni
        // se le borra el token ni se audita como si fuera un empleado.
        if ($user instanceof ManagementActor && $user->isSupportActor()) {
            return response()->noContent();
        }

        $token = $user?->currentAccessToken();

        if ($user !== null && $token instanceof PersonalAccessToken) {
            $uuid = $user->getAttribute('uuid');

            $handler->handle(
                $token->id,
                $user->getTable() === self::MANAGEMENT_TABLE
                    ? AuthChannel::MANAGEMENT
                    : AuthChannel::PORTAL,
                is_string($uuid) && $uuid !== '' ? $uuid : null,
            );
        }

        return response()->noContent();
    }
}
