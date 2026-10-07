<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Identity\Application\UseCase\ManagementAccountProvisioned;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Esquema `ManagementAccountProvisioned` (RF-ID-10): la cuenta y su contrasena
 * temporal, **dos objetos y no uno**, por lo mismo que `EmployeeProvisioned`: la
 * cuenta es lo que se lista y se vuelve a pedir, y la contrasena un secreto que
 * existe en esta respuesta y en ninguna otra.
 */
final class ManagementAccountProvisionedResource extends JsonResource
{
    public static $wrap;

    public function __construct(
        private readonly ManagementAccountView $account,
        private readonly ManagementAccountProvisioned $provisioned,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account' => new ManagementAccountResource($this->account)->toArray($request),
            'temporary_password' => new TemporaryPasswordIssuedResource(
                $this->provisioned->account->uuid,
                $this->provisioned->password,
                $this->provisioned->issuedAt,
                $this->provisioned->expiresAt,
            )->toArray($request),
        ];
    }
}
