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
 *
 * ## Dos contadores con la misma clase, y no uno
 *
 * Desde la tarea 2.1 esta clase sirve tambien al **segundo factor** (RS-06), con
 * otro prefijo y otras dos claves de configuracion: el enlace contextual esta en
 * `IdentityServiceProvider`. Se parametriza en lugar de duplicar la clase porque
 * lo que cambia son tres cadenas, no el comportamiento.
 *
 * **Que sean dos contadores y no uno si importa.** Compartirlo tendria dos
 * efectos malos a la vez: gastar el cupo probando codigos dejaria a alguien sin
 * poder reintentar su contrasena, y alternar `/auth/login` con `/auth/2fa/verify`
 * duplicaria los intentos disponibles para quien ataca.
 *
 * **Las claves de configuracion se leen en cada llamada y no en el constructor**,
 * por lo mismo que el limitador del portal: leerlas al arrancar las congelaria en
 * el valor que tuviera la configuracion en el `boot()`, y entonces `config:cache`
 * —o una prueba que las cambie— no tendria efecto hasta reiniciar el proceso.
 */
final readonly class CacheLoginAttempts implements LoginAttempts
{
    /**
     * @param  string  $prefix  Evita que este contador choque con el `throttle` de la
     *                          ruta, que usa el mismo almacen y una clave parecida —son
     *                          dos controles distintos, peticiones por origen y fallos
     *                          por cuenta— y separa ademas el contador de la contrasena
     *                          del del segundo factor.
     * @param  string  $maxAttemptsKey  Clave de configuracion del umbral de fallos.
     * @param  string  $lockoutSecondsKey  Clave de configuracion de la duracion del bloqueo.
     */
    public function __construct(
        private RateLimiter $limiter,
        private string $prefix = 'identity:login-failures:',
        private string $maxAttemptsKey = 'identity.login.max_attempts',
        private string $lockoutSecondsKey = 'identity.login.lockout_seconds',
    ) {}

    public function isLocked(string $key): bool
    {
        return $this->limiter->tooManyAttempts($this->prefix.$key, $this->maxAttempts());
    }

    public function secondsUntilUnlock(string $key): int
    {
        return $this->limiter->availableIn($this->prefix.$key);
    }

    public function recordFailure(string $key): void
    {
        $this->limiter->hit($this->prefix.$key, $this->lockoutSeconds());
    }

    public function clear(string $key): void
    {
        $this->limiter->clear($this->prefix.$key);
    }

    private function maxAttempts(): int
    {
        return max(1, config()->integer($this->maxAttemptsKey));
    }

    private function lockoutSeconds(): int
    {
        return max(1, config()->integer($this->lockoutSecondsKey));
    }
}
