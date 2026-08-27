<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * La orden de revocar una credencial (RF-QR-03).
 *
 * `reason` no es opcional en ninguna capa: lo exige el dominio, lo exige el
 * CHECK `credentials_chk_revocation_has_reason` y lo exige el asiento de
 * `audit_log`. Una revocacion sin motivo es un fichaje que dejo de poder hacerse
 * sin que nadie sepa por que.
 */
final readonly class RevokeCredentialCommand
{
    public function __construct(
        public string $credentialUuid,
        public string $reason,
        public ?int $actorUserId = null,
    ) {}
}
