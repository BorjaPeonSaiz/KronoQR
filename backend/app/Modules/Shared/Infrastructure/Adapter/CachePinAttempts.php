<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\PinAttempts;
use Illuminate\Cache\RateLimiter;

/**
 * Bloqueo del PIN sobre el limitador de Laravel (RS-12, RF-ID-09).
 *
 * **En cache compartida y no en la fila del empleado.** Un contador de intentos
 * fallidos es efimero por naturaleza y de escritura frecuente: llevarlo a
 * `employees` convertiria cada PIN mal tecleado en un `UPDATE` sobre la fila de
 * una persona —con su `updated_at` cambiando, su fila reescrita y su indice
 * tocado— y dejaria en la tabla de la plantilla un dato que caduca solo. Es la
 * misma decision, y por los mismos motivos, que tomo el bloqueo del panel en
 * `Identity\Infrastructure\Adapter\CacheLoginAttempts` —nombrado en prosa y no
 * con `@see`, porque una referencia resoluble seria una dependencia entre
 * modulos que la frontera del §1.6 no concede—.
 *
 * En produccion la cache es Redis, compartida por todos los trabajadores PHP: un
 * contador por proceso no contaria nada. En la suite es el driver `array`, que
 * basta porque cada prueba corre en un proceso.
 *
 * **Umbral y duracion son configuracion** (regla dura 13):
 * `IDENTITY_PIN_MAX_ATTEMPTS` e `IDENTITY_PIN_LOCKOUT_SECONDS`.
 */
final readonly class CachePinAttempts implements PinAttempts
{
    /**
     * Prefijo propio para no chocar ni con el `throttle` de la ruta ni con el
     * bloqueo del panel: son tres controles distintos sobre el mismo almacen, y
     * compartir clave dejaria dos de ellos sin efecto.
     */
    private const string PREFIX = 'workforce:pin-failures:';

    public function __construct(private RateLimiter $limiter) {}

    public function isLocked(string $employeeUuid): bool
    {
        return $this->limiter->tooManyAttempts(self::PREFIX.$employeeUuid, $this->maxAttempts());
    }

    public function secondsUntilUnlock(string $employeeUuid): int
    {
        return $this->limiter->availableIn(self::PREFIX.$employeeUuid);
    }

    public function recordFailure(string $employeeUuid): void
    {
        $this->limiter->hit(self::PREFIX.$employeeUuid, $this->lockoutSeconds());
    }

    public function clear(string $employeeUuid): void
    {
        $this->limiter->clear(self::PREFIX.$employeeUuid);
    }

    private function maxAttempts(): int
    {
        return max(1, config()->integer('identity.pin.max_attempts'));
    }

    private function lockoutSeconds(): int
    {
        return max(1, config()->integer('identity.pin.lockout_seconds'));
    }
}
