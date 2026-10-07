<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Domain\ValueObject\TemporaryPassword;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Esquema `TemporaryPasswordIssued` (RF-ID-10): la contrasena temporal en
 * claro. **La unica representacion en la que existe**, y solo en la respuesta
 * que la emite, servida con `Cache-Control: no-store, private`.
 */
final class TemporaryPasswordIssuedResource extends JsonResource
{
    public static $wrap;

    public function __construct(
        private readonly string $accountUuid,
        private readonly TemporaryPassword $password,
        private readonly DateTimeImmutable $issuedAt,
        private readonly DateTimeImmutable $expiresAt,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_uuid' => $this->accountUuid,
            'password' => $this->password->plain,
            'issued_at' => $this->issuedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $this->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
