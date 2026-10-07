<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeInterface;
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
            'last_login_at' => $account->lastLoginAt === null ? null : $this->utc($account->lastLoginAt),
            'created_at' => $this->utc($account->createdAt),
        ];
    }

    /** Instante en UTC con sufijo `Z`, como `UtcTimestamp` del contrato. */
    private function utc(DateTimeInterface $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s\Z');
    }
}
