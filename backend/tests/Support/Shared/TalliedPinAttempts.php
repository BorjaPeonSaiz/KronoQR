<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;

/**
 * Decorador del contador que **apunta en un fichero cada fallo anotado contra
 * un sujeto**, desde cualquier proceso.
 *
 * Existe para las pruebas de concurrencia del bloqueo por empleado: lo que hay
 * que comparar es cuantos intentos se probaron de verdad contra el PIN de esa
 * persona —cada uno termina en un `recordFailure()` contra su UUID— con cuantos
 * guarda el contador al final. Los procesos hijos heredan el decorador del
 * contenedor al bifurcar, y el fichero, abierto en modo anadir, junta lo de
 * todos: una linea por escritura, sin pisarse.
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

    public function recordFailure(string $employeeUuid, PinOrigin $origin): int
    {
        $opened = $this->inner->recordFailure($employeeUuid, $origin);

        if ($employeeUuid === $this->subject) {
            file_put_contents($this->tallyFile, $origin->value."\n", FILE_APPEND | LOCK_EX);
        }

        return $opened;
    }

    public function clear(string $employeeUuid): void
    {
        $this->inner->clear($employeeUuid);
    }

    /**
     * Cuantos fallos se anotaron contra el sujeto, en todos los procesos.
     */
    public function tally(): int
    {
        $lines = @file($this->tallyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : \count($lines);
    }

    public function forgetTally(): void
    {
        if (is_file($this->tallyFile)) {
            unlink($this->tallyFile);
        }
    }
}
