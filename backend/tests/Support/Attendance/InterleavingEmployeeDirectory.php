<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use Closure;

/**
 * El directorio de verdad, con un gancho que corre **justo despues de la primera
 * lectura de la instantanea** de una persona (RN-14, ADR-046; revision del
 * bloque 17).
 *
 * Es el mismo patron que `Tests\Support\Workforce\InterleavingEmployeeRepository`
 * para el alta manual de tramos, que lee por este puerto y no por el
 * repositorio de la plantilla: entre esa lectura —sin candado y fuera de la
 * transaccion— y la escritura del tramo corre, en otra sesion, la baja de esa
 * persona. **Se dispara una sola vez**: la relectura con la cadena tomada ya no
 * lo hace, y es la que tiene que ver la baja.
 */
final class InterleavingEmployeeDirectory implements EmployeeDirectory
{
    private bool $fired = false;

    /**
     * @param  Closure(): void  $afterRead
     */
    public function __construct(
        private readonly EmployeeDirectory $inner,
        private readonly string $employeeUuid,
        private readonly Closure $afterRead,
    ) {}

    public function find(string $employeeUuid): ?EmployeeSnapshot
    {
        $snapshot = $this->inner->find($employeeUuid);

        if (! $this->fired && $employeeUuid === $this->employeeUuid) {
            $this->fired = true;
            ($this->afterRead)();
        }

        return $snapshot;
    }
}
