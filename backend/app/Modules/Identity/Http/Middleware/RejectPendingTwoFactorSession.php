<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasAbilities;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la puerta a las **sesiones pendientes de segundo factor** en los
 * endpoints que no las esperan (RS-06).
 *
 * ## Por que hace falta, si el ambito ya deberia bastar
 *
 * En casi toda la API basta: cada ruta exige su ambito con el middleware
 * `ability`, y un token que solo lleva `2fa:pending` no tiene ninguno de ellos.
 * La excepcion es `GET /api/v1/auth/me`, que **no puede exigir un ambito
 * concreto**: lo llaman los cuatro roles de gestion y cada uno lleva los suyos.
 * Sin este middleware, `me` seria el unico endpoint alcanzable con media
 * autenticacion.
 *
 * Que lo que `me` devuelve sean los datos de quien ya acerto su contrasena, y no
 * de terceros, no lo hace inocuo: adelanta el rol y el **alcance por departamento**
 * de la cuenta a quien todavia no ha demostrado tener el segundo factor, y eso es
 * informacion util para decidir a que cuenta merece la pena seguir atacando.
 *
 * ## Por que se declara por ausencia y no por presencia
 *
 * Comprueba que el token **no** lleve `2fa:pending`, en lugar de exigir que lleve
 * alguno de los ambitos de gestion. Con la lista de ambitos habria que ampliarla
 * cada vez que apareciera uno nuevo, y olvidarla dejaria fuera a un rol legitimo;
 * con la ausencia, cualquier ambito futuro pasa y la unica cosa que se bloquea es
 * la que se quiere bloquear.
 *
 * ## `401` y no `403`
 *
 * Para el cliente, esto no es «no puedes» sino «todavia no has terminado de
 * entrar», y el panel ya sabe reaccionar a un `401` volviendo al acceso. Un `403`
 * le diria que su sesion vale y que el problema son sus permisos, que es
 * exactamente lo contrario.
 */
final class RejectPendingTwoFactorSession
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException cuando el token es una sesion pendiente
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof HasAbilities && $token->can(TokenAbility::TWO_FACTOR_PENDING->value)) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
