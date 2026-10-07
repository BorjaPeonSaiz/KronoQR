<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\PasswordHasher;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

/**
 * Hash de contrasenas de gestion con el `Hasher` de la aplicacion (`bcrypt`,
 * coste de `config/hashing.php`). Es el mismo que usa el cast `hashed` del
 * modelo `User`, asi que un hash calculado aqui es exactamente el que ese cast
 * habria guardado.
 */
final readonly class LaravelPasswordHasher implements PasswordHasher
{
    public function __construct(private Hasher $hasher) {}

    public function hash(#[SensitiveParameter] string $password): string
    {
        return $this->hasher->make($password);
    }

    public function matches(#[SensitiveParameter] string $password, string $hash): bool
    {
        return $this->hasher->check($password, $hash);
    }
}
