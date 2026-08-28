<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;

/**
 * Espia del contador de intentos: **delega en el real y apunta la secuencia**.
 *
 * Existe para una sola pregunta, la de RS-03: ¿el camino de un codigo que no
 * existe hace el mismo trabajo que el de un PIN equivocado? El coste dominante
 * —`bcrypt`— ya estaba igualado con el hash señuelo, pero las llamadas al
 * contador no lo estaban: la rama sin empleado se ahorraba una lectura y una
 * escritura de cache que la rama con empleado si pagaba.
 *
 * **Decora en vez de sustituir** porque el comportamiento tiene que ser el de
 * verdad: un doble que devolviera siempre cero no ejercitaria el flanco del
 * bloqueo, que es donde vivia media asimetria.
 *
 * Se apunta el **metodo y el origen, no el sujeto**: los dos caminos cuentan
 * contra sujetos distintos a proposito —el UUID real y el señuelo— y comparar el
 * sujeto haria fallar la prueba por lo unico que si tiene que diferir.
 */
final class RecordingPinAttempts implements PinAttempts
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly PinAttempts $inner) {}

    public function isLocked(string $employeeUuid, PinOrigin $origin): bool
    {
        $this->calls[] = 'isLocked:'.$origin->value;

        return $this->inner->isLocked($employeeUuid, $origin);
    }

    public function secondsUntilUnlock(string $employeeUuid, PinOrigin $origin): int
    {
        $this->calls[] = 'secondsUntilUnlock:'.$origin->value;

        return $this->inner->secondsUntilUnlock($employeeUuid, $origin);
    }

    public function recordFailure(string $employeeUuid, PinOrigin $origin): void
    {
        $this->calls[] = 'recordFailure:'.$origin->value;

        $this->inner->recordFailure($employeeUuid, $origin);
    }

    public function clear(string $employeeUuid): void
    {
        $this->calls[] = 'clear';

        $this->inner->clear($employeeUuid);
    }

    /**
     * Vacia lo apuntado y devuelve la secuencia observada hasta ahora.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        $calls = $this->calls;

        $this->calls = [];

        return $calls;
    }
}
