<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use LogicException;

/**
 * El emisor de tokens visto por los casos de uso del ciclo de vida de una
 * cuenta: solo revoca y promociona, y anota a quien. Emitir una sesion no es
 * cosa de esos casos de uso, y si lo intentaran la prueba fallaria aqui.
 */
final class RecordingAccessTokens implements AccessTokenIssuer
{
    /** @var list<string> */
    public array $revokedAccounts = [];

    /** @var list<int|string> */
    public array $revokedTokens = [];

    /** @var list<array{0: string, 1: int|string}> */
    public array $revokedAllExcept = [];

    /** @var list<array{0: string, 1: int|string, 2: list<string>}> */
    public array $promoted = [];

    /** Lo que responde `promoteToFullSession`: `false` simula un token ya revocado. */
    public bool $tokenStillExists = true;

    public function issueFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken
    {
        throw new LogicException('El ciclo de vida de una cuenta no emite sesiones.');
    }

    public function issuePendingFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken
    {
        throw new LogicException('El ciclo de vida de una cuenta no emite retos de segundo factor.');
    }

    public function revoke(int|string $tokenId): void
    {
        $this->revokedTokens[] = $tokenId;
    }

    public function revokeAllFor(string $userUuid): void
    {
        $this->revokedAccounts[] = $userUuid;
    }

    public function revokeAllExcept(string $userUuid, int|string $keepTokenId): void
    {
        $this->revokedAllExcept[] = [$userUuid, $keepTokenId];
    }

    public function promoteToFullSession(AuthenticatedUser $user, int|string $tokenId): bool
    {
        $this->promoted[] = [$user->uuid, $tokenId, $user->abilityNames()];

        return $this->tokenStillExists;
    }
}
