<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\ChangeOwnPasswordHandler;
use App\Modules\Identity\Application\UseCase\ChangeOwnPasswordOutcome;
use App\Modules\Identity\Http\Request\ChangeOwnPasswordRequest;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/auth/password` (**RF-ID-10**): el cambio de la contrasena
 * propia, y la salida de la sesion de contrasena temporal.
 *
 * - `204`: cambiada; las demas sesiones cerradas y **esta, en la misma
 *   transaccion, con los ambitos del rol** —el panel relee `/auth/me` y sigue,
 *   sin volver a entrar—.
 * - `422` en `current_password` (no coincide) o en `new_password` (politica o
 *   igual a la actual), sin `401`: la sesion sigue siendo valida.
 * - `409`: un `admin` la restablecio mientras tanto.
 * - `401`: la sesion ya no sirve (baja, restablecimiento, temporal caducada).
 * - `429` con `Retry-After`: lo pone `AccountTemporarilyLocked`.
 */
final class OwnPasswordController extends Controller
{
    /**
     * @throws AuthenticationException cuando la sesion ya no sirve
     */
    public function __invoke(ChangeOwnPasswordRequest $request, ChangeOwnPasswordHandler $handler): Response
    {
        return match ($handler->handle($request->toCommand())) {
            ChangeOwnPasswordOutcome::Changed => response()->noContent(),
            ChangeOwnPasswordOutcome::WrongCurrentPassword => ProblemDetails::validationFailed([
                'current_password' => [ProblemDetails::translated(
                    'accounts.current_password_incorrect',
                    [],
                    'La contrasena actual no es correcta.',
                )],
            ]),
            ChangeOwnPasswordOutcome::SameAsCurrent => ProblemDetails::validationFailed([
                'new_password' => [ProblemDetails::translated(
                    'accounts.new_password_same',
                    [],
                    'La contrasena nueva tiene que ser distinta de la actual.',
                )],
            ]),
            ChangeOwnPasswordOutcome::ChangedMeanwhile => ProblemDetails::conflict(ProblemDetails::translated(
                'accounts.password_changed_meanwhile',
                [],
                'Tu contrasena ha cambiado mientras la editabas. Vuelve a entrar.',
            )),
            ChangeOwnPasswordOutcome::SessionGone => throw new AuthenticationException,
        };
    }
}
