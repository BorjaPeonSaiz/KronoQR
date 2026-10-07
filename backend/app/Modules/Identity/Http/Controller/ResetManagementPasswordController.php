<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCase\ManagementPasswordResetStatus;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use App\Modules\Identity\Http\Request\ResetManagementPasswordRequest;
use App\Modules\Identity\Http\Resource\TemporaryPasswordIssuedResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/management-accounts/{uuid}/password/reset` (**RF-ID-10**, RS-06).
 *
 * `404` para una cuenta que no existe o esta dada de baja —a una baja no se le
 * devuelve el acceso cambiandole la contrasena—; `409` sobre la propia, que se
 * cambia con `POST /auth/password`. La temporal viaja una sola vez, con
 * `Cache-Control: no-store, private`.
 */
final class ResetManagementPasswordController extends Controller
{
    public function __invoke(
        ResetManagementPasswordRequest $request,
        string $uuid,
        ResetManagementPasswordHandler $handler,
    ): JsonResponse {
        $outcome = $handler->handle($request->toCommand($uuid));

        return match ($outcome->status) {
            ManagementPasswordResetStatus::NotFound => ProblemDetails::notFound(),
            ManagementPasswordResetStatus::OwnAccount => ProblemDetails::conflict(ProblemDetails::translated(
                'accounts.own_account_password_reset',
                [],
                'No puedes restablecer tu propia contrasena.',
            )),
            ManagementPasswordResetStatus::Reset => new TemporaryPasswordIssuedResource(
                $uuid,
                $outcome->password(),
                $outcome->issuedAt(),
                $outcome->expiresAt(),
            )->response()->header('Cache-Control', 'no-store, private'),
        };
    }
}
