<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\LogOutHandler;
use App\Modules\Identity\Http\Policy\PortalSessionPolicy;
use App\Modules\Identity\Http\Request\PortalLogoutRequest;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/me/logout` — cierre de la sesion del portal del empleado
 * (RF-ID-05, RS-03; PO1 de la verificacion de la 2.1.0).
 *
 * Revoca **el token de esta llamada** y ninguno mas: cerrar sesion en el
 * ordenador de la sala de personal no puede echar a la misma persona del movil
 * desde el que tambien estaba mirando sus horas.
 *
 * ## Por que una ruta propia y no `POST /auth/logout`
 *
 * Aquella acepta cualquier token y decide el canal por descarte. Esta es
 * **exclusiva del portal**: ambito `self:read` en la ruta y
 * {@see PortalSessionPolicy} en la peticion,
 * con su prueba negativa por cada rol (regla dura 18). El canal no se deduce:
 * es `PORTAL` porque la policy ya garantizo que el portador es una persona de la
 * plantilla.
 *
 * ## El caso de uso es el mismo
 *
 * {@see LogOutHandler} revoca y deja el rastro que corresponda al canal. Para el
 * portal no hay asiento en `audit_log` (ADR-039: no existe tipo de actor para un
 * empleado) y si la linea `auth.logged_out` del log tecnico, con el UUID y nunca
 * el nombre (regla dura 21). Duplicar aqui la revocacion seria un segundo sitio
 * donde olvidarse de ese rastro.
 */
final class PortalLogoutController extends Controller
{
    public function __invoke(PortalLogoutRequest $request, LogOutHandler $handler): Response
    {
        $employee = $request->user();
        $token = $employee?->currentAccessToken();

        if ($employee !== null && $token instanceof PersonalAccessToken) {
            $uuid = $employee->getAttribute('uuid');

            $handler->handle(
                $token->id,
                AuthChannel::PORTAL,
                \is_string($uuid) && $uuid !== '' ? $uuid : null,
            );
        }

        return response()->noContent();
    }
}
