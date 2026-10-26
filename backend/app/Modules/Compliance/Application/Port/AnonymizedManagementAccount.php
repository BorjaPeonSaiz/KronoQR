<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Una cuenta de gestion recien minimizada (ADR-060): lo que
 * necesita su asiento `retention.management_account_anonymized`. Sin nombre ni
 * correo: ya no existen.
 */
final readonly class AnonymizedManagementAccount
{
    /**
     * Los campos que la minimizacion deja vacios o sustituye. La misma lista que
     * el `CHECK users_chk_anonymized_is_minimised`.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'name',
        'email',
        'password',
        'remember_token',
        'last_login_at',
        'temporary_password_expires_at',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'two_factor_last_slice',
    ];

    public function __construct(
        public int $userId,
        public string $userUuid,
        /** Fecha de la baja, `YYYY-MM-DD` en UTC. */
        public string $deactivatedOn,
        public int $tokensDeleted,
    ) {}
}
