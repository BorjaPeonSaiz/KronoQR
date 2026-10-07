<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Http\Support\PasswordChangeSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra a la sesion de contrasena temporal (`password:change`, RF-ID-10) las
 * rutas autenticadas que **no exigen ambito** y por eso no la rechaza el
 * middleware `ability`: hoy, `POST /api/v1/client-errors`.
 *
 * Las demas rutas sin ambito —`GET /auth/me`, `POST /auth/logout` y
 * `POST /auth/password`— son justamente las tres que esa sesion SI alcanza, y
 * no llevan este middleware. `PasswordChangeSessionRoutesTest` recorre el
 * router y falla si aparece una ruta autenticada nueva sin ambito y sin esto.
 */
final class RejectPasswordChangeSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (PasswordChangeSession::isOpen($request)) {
            return PasswordChangeSession::response();
        }

        return $next($request);
    }
}
