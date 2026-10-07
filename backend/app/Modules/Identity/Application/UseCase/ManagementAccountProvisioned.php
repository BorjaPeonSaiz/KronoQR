<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\TemporaryPassword;
use DateTimeImmutable;

/**
 * Una cuenta de gestion recien creada con su contrasena temporal (RF-ID-10).
 *
 * **La unica vez que la contrasena existe en claro fuera del generador.** Quien
 * la recibe —el controlador o el comando de consola— la enseña una vez y no la
 * guarda en ningun sitio.
 */
final readonly class ManagementAccountProvisioned
{
    public function __construct(
        public AuthenticatedUser $account,
        public TemporaryPassword $password,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
