<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Query\ManagementAccountsQuery;
use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\TwoFactorResetOutcome;
use App\Modules\Identity\Http\Request\ResetManagementTwoFactorRequest;
use App\Modules\Identity\Http\Resource\ManagementAccountResource;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/management-accounts/{uuid}/two-factor/reset` (**RF-ID-10**,
 * RS-06): `200` con la cuenta ya sin segundo factor; `404` no existe o de baja;
 * `409` sobre la propia o sobre una cuenta sin segundo factor confirmado.
 */
final class ResetManagementTwoFactorController extends Controller
{
    public function __invoke(
        ResetManagementTwoFactorRequest $request,
        string $uuid,
        ResetTwoFactorHandler $handler,
        ManagementAccountsQuery $query,
    ): JsonResponse {
        return match ($handler->handle($request->toCommand($uuid))) {
            TwoFactorResetOutcome::NotFound => ProblemDetails::notFound(),
            TwoFactorResetOutcome::OwnAccount => ProblemDetails::conflict(ProblemDetails::translated(
                'accounts.own_account_two_factor_reset',
                [],
                'No puedes retirar tu propio segundo factor.',
            )),
            TwoFactorResetOutcome::NotEnrolled => ProblemDetails::conflict(ProblemDetails::translated(
                'accounts.two_factor_not_enrolled',
                [],
                'Esa cuenta no tiene segundo factor activo.',
            )),
            TwoFactorResetOutcome::Reset => $this->account($query, $uuid),
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
