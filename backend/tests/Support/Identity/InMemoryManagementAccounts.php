<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\AccountSnapshot;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;

/**
 * El padron de cuentas de gestion visto por el puerto del ciclo de vida, en
 * memoria y sin base de datos (RS-05, RS-06, RF-ID-10).
 *
 * Varias cuentas, porque desde la 2.2.0 la baja decide con el padron entero
 * —«¿es la ultima `admin` activa?»—. Los constructores con nombre dicen el caso
 * sin comentarios; `deactivated`, `passwords` y `locks` dejan ver **lo que se
 * escribio y en que orden**, que es lo que separa «no hizo nada» de «hizo algo
 * que no se ve».
 */
final class InMemoryManagementAccounts implements ManagementAccountLifecycle
{
    /** El uuid publico de la cuenta principal del escenario (UUID v7 escrito a mano). */
    public const string UUID = '0199c4a1-6f2d-7b10-9e3a-4c81d5f20b77';

    /** Hash de partida de cada cuenta del escenario. No es un hash real: el hasher es un doble. */
    public const string INITIAL_HASH = 'hash:Contrasena-Actual-1!';

    /** @var list<string> */
    public array $deactivated = [];

    /**
     * Hash y caducidad fijados por uuid, tal cual llegaron al puerto.
     *
     * @var array<string, array{hash: string, expires: ?DateTimeImmutable}>
     */
    public array $passwords = [];

    /**
     * Cada `lockForUpdate`, en orden. Lo que deja afirmar que una decision se
     * tomo con la fila bloqueada.
     *
     * @var list<string>
     */
    public array $locks = [];

    /**
     * @var array<string, array{email: string, active: bool, roles: list<UserRole>, twoFactor: bool, hash: string}>
     */
    private array $accounts = [];

    public static function empty(): self
    {
        return new self;
    }

    public static function withActiveAccount(string $email, string $uuid = self::UUID, UserRole $role = UserRole::RRHH): self
    {
        return (new self)->with($uuid, $email, $role);
    }

    public static function withDeactivatedAccount(string $email, string $uuid = self::UUID): self
    {
        return (new self)->with($uuid, $email, UserRole::RRHH, active: false);
    }

    public function with(
        string $uuid,
        string $email,
        UserRole $role,
        bool $active = true,
        bool $twoFactor = false,
    ): self {
        $this->accounts[$uuid] = [
            'email' => $email,
            'active' => $active,
            'roles' => [$role],
            'twoFactor' => $twoFactor,
            'hash' => self::INITIAL_HASH,
        ];

        return $this;
    }

    public function uuidOfAccount(string $email): ?string
    {
        foreach ($this->accounts as $uuid => $account) {
            if ($account['email'] === $email) {
                return $uuid;
            }
        }

        return null;
    }

    public function lockAccount(string $uuid): ?AccountSnapshot
    {
        $this->locks[] = $uuid;

        $account = $this->accounts[$uuid] ?? null;

        return $account === null
            ? null
            : new AccountSnapshot($uuid, $account['active'], $account['roles'], $account['twoFactor']);
    }

    public function countActiveAdmins(): int
    {
        return \count(array_filter(
            $this->accounts,
            static fn (array $account): bool => $account['active'] && \in_array(UserRole::ADMIN, $account['roles'], true),
        ));
    }

    public function deactivate(string $uuid): void
    {
        $this->deactivated[] = $uuid;

        if (isset($this->accounts[$uuid])) {
            $this->accounts[$uuid]['active'] = false;
        }
    }

    public function currentPasswordHash(string $uuid): ?string
    {
        $account = $this->accounts[$uuid] ?? null;

        return $account !== null && $account['active'] ? $account['hash'] : null;
    }

    public function replacePasswordHash(string $uuid, string $hash, ?DateTimeImmutable $temporaryExpiresAt): void
    {
        $this->passwords[$uuid] = ['hash' => $hash, 'expires' => $temporaryExpiresAt];

        if (isset($this->accounts[$uuid])) {
            $this->accounts[$uuid]['hash'] = $hash;
        }
    }

    /**
     * Simula un restablecimiento cruzado: otra peticion cambio el hash.
     */
    public function overwriteHash(string $uuid, string $hash): void
    {
        $this->accounts[$uuid]['hash'] = $hash;
    }
}
