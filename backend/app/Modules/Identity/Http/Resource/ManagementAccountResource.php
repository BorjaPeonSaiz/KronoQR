<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\UtcPrecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Esquema `ManagementAccount` (RF-ID-10): la cuenta vista por quien las
 * administra. Envuelve una vista de lectura, **nunca el modelo**, y no lleva
 * ningun secreto: ni hash, ni temporal, ni secreto TOTP.
 */
final class ManagementAccountResource extends JsonResource
{
    public static $wrap;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ManagementAccountView $view */
        $view = $this->resource;
        $account = $view->account;

        return [
            'uuid' => $account->uuid,
            'name' => $account->name,
            'email' => $account->email,
            'locale' => $account->locale,
            'roles' => array_map(static fn (UserRole $role): string => $role->value, $account->roles),
            'scope' => [
                'kind' => $account->scope->isUnrestricted() ? 'all' : 'departments',
                'department_ids' => $account->scope->departmentIds(),
            ],
            'status' => $account->active ? 'active' : 'deactivated',
            'two_factor_enabled' => $account->twoFactorEnabled,
            'password_status' => $view->passwordStatus->value,
            // UTC con `Z` (regla dura 3): la conversion a la zona del centro es
            // cosa del panel.
            'last_login_at' => UtcInstant::format($account->lastLoginAt, UtcPrecision::Seconds),
            'created_at' => UtcInstant::of($account->createdAt, UtcPrecision::Seconds),
            // ADR-060: lo que dice al panel que la cuenta esta minimizada.
            'anonymized_at' => UtcInstant::format($account->anonymizedAt, UtcPrecision::Seconds),
        ];
    }
}
