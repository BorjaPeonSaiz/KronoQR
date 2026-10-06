<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinAttemptReservation;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;

/**
 * Decorador del contador que **apunta en un fichero cada reserva contra un
 * sujeto que deja comparar contra su PIN real**, desde cualquier proceso.
 *
 * Existe para las pruebas de concurrencia del bloqueo por empleado: lo que hay
 * que acotar es cuantos intentos se probaron de verdad contra el PIN de esa
 * persona —los que reservaron sin encontrar el bloqueo abierto— y cuantos
 * abrieron un bloqueo. Los procesos hijos heredan el decorador del contenedor al
 * bifurcar, y los ficheros, abiertos en modo anadir, juntan lo de todos: una
 * linea por escritura, sin pisarse.
 */
final readonly class TalliedPinAttempts implements PinAttempts
{
    public function __construct(
        private PinAttempts $inner,
        private string $subject,
        private string $tallyFile,
    ) {}

    public function isLocked(string $employeeUuid, PinOrigin $origin): bool
    {
        return $this->inner->isLocked($employeeUuid, $origin);
    }

    public function secondsUntilUnlock(string $employeeUuid, PinOrigin $origin): int
    {
        return $this->inner->secondsUntilUnlock($employeeUuid, $origin);
    }

    public function reserve(string $employeeCode, ?string $employeeUuid, PinOrigin $origin): PinAttemptReservation
    {
        $reservation = $this->inner->reserve($employeeCode, $employeeUuid, $origin);

        // Solo las reservas que dejan comparar contra el PIN real: las que llegan
        // con el bloqueo abierto se comparan contra el señuelo.
        if ($employeeUuid === $this->subject && ! $reservation->isLocked()) {
            file_put_contents($this->tallyFile, $origin->value."\n", FILE_APPEND | LOCK_EX);
        }

        if ($employeeUuid === $this->subject && $reservation->opensLockout()) {
            file_put_contents($this->tallyFile.'.opened', $origin->value."\n", FILE_APPEND | LOCK_EX);
        }

        return $reservation;
    }

    public function clear(string $employeeUuid): void
    {
        $this->inner->clear($employeeUuid);
    }

    /**
     * Cuantas reservas contra el sujeto dejaron comparar contra su PIN real, en
     * todos los procesos.
     */
    public function tally(): int
    {
        return self::linesOf($this->tallyFile);
    }

    /**
     * Cuantas reservas contra el sujeto abrieron un bloqueo, en todos los
     * procesos.
     */
    public function openedTally(): int
    {
        return self::linesOf($this->tallyFile.'.opened');
    }

    public function forgetTally(): void
    {
        foreach ([$this->tallyFile, $this->tallyFile.'.opened'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private static function linesOf(string $file): int
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : \count($lines);
    }
}
