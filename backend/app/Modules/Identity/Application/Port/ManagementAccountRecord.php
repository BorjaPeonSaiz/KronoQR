<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;

/**
 * Una cuenta de gestion tal y como la lee quien las administra (RF-ID-10).
 *
 * **Ningun secreto**: ni el hash de la contrasena, ni la temporal, ni el
 * secreto TOTP. La caducidad de la temporal si viaja, porque de ella sale
 * `password_status`, que se resuelve con el reloj en la capa de aplicacion y no
 * en el adaptador.
 */
final readonly class ManagementAccountRecord
{
    /**
     * @param  list<UserRole>  $roles
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public string $email,
        public string $locale,
        public array $roles,
        public AccessScope $scope,
        public bool $active,
        public bool $twoFactorEnabled,
        public ?DateTimeImmutable $temporaryPasswordExpiresAt,
        public ?DateTimeImmutable $lastLoginAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
