<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Query\ManagementAccountsQuery;
use App\Modules\Identity\Application\UseCase\AccountDeactivationOutcome;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use App\Modules\Identity\Http\Request\DeactivateManagementAccountRequest;
use App\Modules\Identity\Http\Resource\ManagementAccountResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/management-accounts/{uuid}/deactivate` (**RF-ID-10**, RS-05).
 *
 * **Un solo `404` para «no existe» y «ya estaba de baja»** (RS-03): la segunda
 * pulsacion de un boton no es un hecho nuevo, y el caso de uso no escribe nada
 * en ninguno de los dos. Las dos negativas de la invariante —la propia cuenta y
 * la ultima `admin` activa— son `409`, cada una con un `detail` que dice que
 * hacer.
 */
final class DeactivateManagementAccountController extends Controller
{
    public function __invoke(
        DeactivateManagementAccountRequest $request,
        string $uuid,
        DeactivateManagementAccountHandler $handler,
        ManagementAccountsQuery $query,
    ): JsonResponse {
        return match ($handler->handle($request->toCommand($uuid))) {
            AccountDeactivationOutcome::NotFound,
            AccountDeactivationOutcome::AlreadyInactive => ProblemDetails::notFound(),
            AccountDeactivationOutcome::OwnAccount => ProblemDetails::conflict(ProblemDetails::translated(
                'accounts.own_account_deactivation',
                [],
                'No puedes dar de baja tu propia cuenta.',
            )),
            AccountDeactivationOutcome::LastActiveAdmin => ProblemDetails::conflict(ProblemDetails::translated(
                'accounts.last_active_admin',
                [],
                'Es la ultima cuenta admin activa.',
            )),
            AccountDeactivationOutcome::Deactivated => $this->account($query, $uuid),
        };
    }

    private function account(ManagementAccountsQuery $query, string $uuid): JsonResponse
    {
        $view = $query->find($uuid);

        return $view === null
            ? ProblemDetails::notFound()
            : new ManagementAccountResource($view)->response();
    }
}
