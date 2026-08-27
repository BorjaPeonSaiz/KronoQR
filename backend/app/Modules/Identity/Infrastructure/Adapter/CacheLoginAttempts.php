<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\LoginAttempts;
use Illuminate\Cache\RateLimiter;

/**
 * Bloqueo por intentos fallidos sobre el limitador de Laravel (RF-ID-01).
 *
 * **Sobre cache compartida y no en memoria del proceso**: en produccion hay
 * varios trabajadores PHP y un contador por proceso no cuenta nada. En
 * desarrollo y en produccion la cache es Redis; en la suite de pruebas es el
 * driver `array`, que basta porque cada prueba corre en un proceso.
 *
 * **Umbral y bloqueo son configuracion, no constantes** (regla dura 13):
 * `IDENTITY_LOGIN_MAX_ATTEMPTS` e `IDENTITY_LOGIN_LOCKOUT_SECONDS`. Un cliente
 * con una politica de seguridad mas dura los ajusta sin tocar el repositorio.
 */
final readonly class CacheLoginAttempts implements LoginAttempts
{
    /**
     * El prefijo evita que este contador choque con el `throttle` de la ruta,
     * que usa el mismo almacen y una clave parecida. Son dos controles distintos
     * —peticiones por origen y fallos por cuenta— y compartir contador dejaria
     * uno de los dos sin efecto.
     */
    private const string PREFIX = 'identity:login-failures:';

    public function __construct(private RateLimiter $limiter) {}

    public function isLocked(string $key): bool
    {
        return $this->limiter->tooManyAttempts(self::PREFIX.$key, $this->maxAttempts());
    }

    public function secondsUntilUnlock(string $key): int
    {
        return $this->limiter->availableIn(self::PREFIX.$key);
    }

    public function recordFailure(string $key): void
    {
        $this->limiter->hit(self::PREFIX.$key, $this->lockoutSeconds());
    }

    public function clear(string $key): void
    {
        $this->limiter->clear(self::PREFIX.$key);
    }

    private function maxAttempts(): int
    {
        return max(1, config()->integer('identity.login.max_attempts'));
    }

    private function lockoutSeconds(): int
    {
        return max(1, config()->integer('identity.login.lockout_seconds'));
    }
}
