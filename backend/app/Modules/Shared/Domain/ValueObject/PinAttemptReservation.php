<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lo que responde el contador al **reservar** un intento de PIN, antes de
 * compararlo (RS-12, ADR-050).
 *
 * Dos desenlaces y nunca los dos a la vez:
 *
 *  - **bloqueado**: el bloqueo ya estaba abierto antes de este intento. El
 *    intento no se anota —el bloqueo no crece por insistir— y el PIN **no se
 *    compara contra el hash real**: si se comparara, el bloqueo seria un oraculo
 *    que confirma cuando se acierta.
 *  - **abierto**: no habia bloqueo, y el intento ya esta anotado como fallo. Si
 *    al anotarlo se alcanza un escalon, {@see self::openedSeconds()} dice cuanto
 *    dura el bloqueo que **abre este intento**: es el flanco con el que quien
 *    llama escribe un solo `auth.lockout_started` por bloqueo. Si el PIN resulta
 *    ser el bueno, quien llama borra la cuenta y la marca reservada con ella.
 *
 * Reservar antes de comparar es lo que hace que la cota por empleado no dependa
 * de cuantos intentos lleguen a la vez: con el candado del empleado cogido,
 * solo los intentos que caben antes del primer escalon llegan a compararse.
 */
final readonly class PinAttemptReservation
{
    private function __construct(
        private int $lockSeconds,
        private int $openedSeconds,
    ) {}

    /**
     * El bloqueo ya estaba abierto: faltan `$seconds` para que se levante.
     */
    public static function locked(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Un intento bloqueado necesita los segundos que faltan para el desbloqueo.');
        }

        return new self($seconds, 0);
    }

    /**
     * No habia bloqueo y el intento queda anotado. `$openedSeconds` es el
     * bloqueo que abre este intento, o cero si no alcanza ningun escalon.
     */
    public static function open(int $openedSeconds = 0): self
    {
        if ($openedSeconds < 0) {
            throw new InvalidArgumentException('Un bloqueo no puede durar un tiempo negativo.');
        }

        return new self(0, $openedSeconds);
    }

    public function isLocked(): bool
    {
        return $this->lockSeconds > 0;
    }

    /**
     * Segundos que faltan para el desbloqueo si el intento llego bloqueado;
     * cero si no.
     */
    public function lockSeconds(): int
    {
        return $this->lockSeconds;
    }

    /**
     * Segundos del bloqueo que abre este intento; cero si no abre ninguno o si
     * llego con el bloqueo ya abierto.
     */
    public function openedSeconds(): int
    {
        return $this->openedSeconds;
    }

    public function opensLockout(): bool
    {
        return $this->openedSeconds > 0;
    }
}
