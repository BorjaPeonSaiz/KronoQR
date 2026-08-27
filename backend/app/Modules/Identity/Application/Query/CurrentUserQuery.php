<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;

/**
 * Quien es el usuario de la sesion en curso, con sus roles y ambitos vigentes
 * (`GET /api/v1/auth/me`).
 *
 * **Se relee de la base de datos y no se sirve del token.** Un rol retirado o
 * una cuenta desactivada tienen que notarse en la siguiente peticion, no cuando
 * caduque la sesion: si el panel siguiera pintando menus por lo que decia un
 * token emitido esta manana, la revocacion seria efectiva solo a ratos.
 */
final readonly class CurrentUserQuery
{
    public function __construct(private UserAccounts $accounts) {}

    public function handle(string $uuid): ?AuthenticatedUser
    {
        return $this->accounts->findByUuid($uuid);
    }
}
