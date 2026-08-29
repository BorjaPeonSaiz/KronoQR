<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\LogOutHandler;
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
