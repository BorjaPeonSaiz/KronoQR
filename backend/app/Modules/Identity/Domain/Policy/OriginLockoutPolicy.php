<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;
use InvalidArgumentException;

/**
 * **Bloqueo por origen del acceso al portal** (RS-12, ADR-050 §2).
 *
 * `maxFailures` fallos dentro de una ventana deslizante de `windowSeconds`
 * cierran el portal a ese origen durante `lockoutSeconds`. Los valores de serie
 * —20 en 15 minutos, 60 minutos de bloqueo— son parametros de seguridad y no
 * umbrales legales: llegan ya resueltos desde `config/identity.php` (reglas duras
 * 13 y 14), nunca escritos aqui.
 *
 * ## Tres decisiones que no son obvias
 *
 * 1. **Durante el bloqueo no se evalua nada.** Las peticiones no cuentan y no lo
 *    alargan: si lo alargaran, quien insiste mantendria cerrado el portal de
 *    toda la red del hotel indefinidamente con una peticion por hora.
 * 2. **Abrir el bloqueo vacia la cuenta.** Al terminar, el origen empieza de
 *    cero: los fallos que lo abrieron ya han pagado su hora.
 * 3. **Un acierto no la vacia.** No hay metodo para eso, y es a proposito: si lo
 *    hubiera, quien conoce su propio PIN lo intercalaria entre intentos contra
 *    los de otros (ADR-050).
 *
 * Pura: ni reloj, ni cache, ni configuracion. Recibe el instante y devuelve el
 * estado siguiente; guardarlo es cosa del puerto `PortalOriginAttempts`.
 */
final readonly class OriginLockoutPolicy
{
    public function __construct(
        private int $maxFailures,
        private int $windowSeconds,
        private int $lockoutSeconds,
    ) {
        if ($maxFailures < 1) {
            throw new InvalidArgumentException('El bloqueo por origen necesita al menos un fallo.');
        }

        if ($windowSeconds < 1 || $lockoutSeconds < 1) {
            throw new InvalidArgumentException('La ventana y el bloqueo por origen necesitan una duracion positiva.');
        }
    }

    /** Segundos que faltan para que el origen vuelva a evaluarse; cero si no esta bloqueado. */
    public function secondsUntilUnlock(OriginAttemptHistory $history, int $now): int
    {
        return $history->lockedUntil === null ? 0 : max(0, $history->lockedUntil - $now);
    }

    /**
     * El estado tras un fallo nuevo.
     *
     * **Sobre un origen bloqueado no cambia nada** (decision 1): devuelve el
     * mismo estado, sin contar el fallo ni alargar el bloqueo. Quien llama ya lo
     * comprueba antes con {@see self::secondsUntilUnlock()}, pero entre esa
     * comprobacion y la escritura otro proceso del mismo origen puede haber
     * abierto el bloqueo; sin esta guarda, el fallo rezagado lo borraria al
     * rehacer la cuenta desde cero.
     */
    public function afterFailure(OriginAttemptHistory $history, int $now): OriginAttemptHistory
    {
        if ($this->secondsUntilUnlock($history, $now) > 0) {
            return $history;
        }

        $floor = $now - $this->windowSeconds;

        $failures = array_values(array_filter(
            $history->failures,
            static fn (int $failure): bool => $failure > $floor,
        ));
        $failures[] = $now;

        if (\count($failures) >= $this->maxFailures) {
            return new OriginAttemptHistory([], $now + $this->lockoutSeconds);
        }

        return new OriginAttemptHistory($failures, null);
    }

    /** Si el paso de `$before` a `$after` acaba de abrir el bloqueo: el flanco, no el estado. */
    public function opened(OriginAttemptHistory $before, OriginAttemptHistory $after, int $now): bool
    {
        return $this->secondsUntilUnlock($before, $now) === 0 && $this->secondsUntilUnlock($after, $now) > 0;
    }

    /**
     * Cuanto tiene que conservarse el estado: lo mas largo entre la ventana y
     * el bloqueo. Pasado ese tiempo sin fallos, la entrada ya no dice nada.
     */
    public function retentionSeconds(): int
    {
        return max($this->windowSeconds, $this->lockoutSeconds);
    }

    public function maxFailures(): int
    {
        return $this->maxFailures;
    }

    public function lockoutSeconds(): int
    {
        return $this->lockoutSeconds;
    }
}
