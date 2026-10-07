<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\ValueObject\TemporaryPassword;
use DateTimeImmutable;
use LogicException;

/**
 * Como termino un restablecimiento de contrasena
 * ({@see ResetManagementPasswordHandler}).
 *
 * Con `Reset`, la contrasena temporal y su vida; con los otros dos, nada, y no
 * se ha escrito nada.
 */
final readonly class ManagementPasswordResetOutcome
{
    private function __construct(
        public ManagementPasswordResetStatus $status,
        private ?TemporaryPassword $password,
        private ?DateTimeImmutable $issuedAt,
        private ?DateTimeImmutable $expiresAt,
    ) {}

    public static function reset(TemporaryPassword $password, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): self
    {
        return new self(ManagementPasswordResetStatus::Reset, $password, $issuedAt, $expiresAt);
    }

    public static function of(ManagementPasswordResetStatus $status): self
    {
        if ($status === ManagementPasswordResetStatus::Reset) {
            throw new LogicException('Un restablecimiento hecho lleva su contrasena: usa reset().');
        }

        return new self($status, null, null, null);
    }

    public function password(): TemporaryPassword
    {
        return $this->password ?? throw new LogicException('Este restablecimiento no llego a ocurrir.');
    }

    public function issuedAt(): DateTimeImmutable
    {
        return $this->issuedAt ?? throw new LogicException('Este restablecimiento no llego a ocurrir.');
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt ?? throw new LogicException('Este restablecimiento no llego a ocurrir.');
    }
}
