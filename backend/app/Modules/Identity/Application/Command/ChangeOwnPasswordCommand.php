<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use SensitiveParameter;

/**
 * Cambio de la contrasena propia (RF-ID-10, `POST /api/v1/auth/password`).
 *
 * La cuenta y el token los resuelve la sesion, nunca el cliente: no hay ningun
 * identificador que manipular para cambiar la contrasena de otra persona.
 */
final readonly class ChangeOwnPasswordCommand
{
    public function __construct(
        public string $accountUuid,
        public int|string $currentTokenId,
        #[SensitiveParameter] public string $currentPassword,
        #[SensitiveParameter] public string $newPassword,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function __debugInfo(): array
    {
        return [
            'accountUuid' => $this->accountUuid,
            'currentTokenId' => $this->currentTokenId,
            'currentPassword' => '***',
            'newPassword' => '***',
        ];
    }
}
