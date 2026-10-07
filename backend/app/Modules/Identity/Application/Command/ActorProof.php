<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use SensitiveParameter;

/**
 * Lo que presenta un `admin` para confirmar que es el antes de actuar sobre
 * otra cuenta (RF-ID-10): el codigo de su autenticador o, si su cuenta no tiene
 * segundo factor confirmado, su contrasena. Solo se usa uno de los dos.
 */
final readonly class ActorProof
{
    public function __construct(
        #[SensitiveParameter] public ?string $totpCode,
        #[SensitiveParameter] public ?string $currentPassword,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['totpCode' => '***', 'currentPassword' => '***'];
    }
}
