<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Domain\Model\Incident;
use RuntimeException;

/**
 * Un libro de incidencias que se rompe con el hallazgo de un empleado concreto
 * y funciona con el resto (RF-PR-01).
 *
 * Existe para probar el aislamiento por hallazgo de la revision diaria: el
 * despachador de eventos de Laravel es **sincrono**, asi que una excepcion al
 * abrir una incidencia vuelve por la pila de quien la publico y, sin `try`,
 * aborta la pasada entera. Lo que esto simula no es raro —un tramo borrado por
 * debajo, una clave ajena que ya no resuelve, la base sin espacio— y el desenlace
 * correcto es abrir las demas y decirlo en el codigo de salida.
 *
 * **Decora al adaptador de verdad** en lugar de fingirlo: lo que hay que probar es
 * que un fallo aislado no impide que el resto se escriba en PostgreSQL, y con un
 * doble en memoria no se escribiria nada.
 */
final readonly class FailingIncidentLedger implements IncidentLedger
{
    public function __construct(
        private IncidentLedger $ledger,
        /** El empleado cuyo hallazgo revienta. Se elige por UUID y no por posicion: el orden lo decide la consulta. */
        private string $failingEmployeeUuid,
    ) {}

    public function openIfAbsent(Incident $incident): ?int
    {
        if ($incident->employeeUuid === $this->failingEmployeeUuid) {
            throw new RuntimeException('El libro de incidencias ha fallado al abrir este hallazgo.');
        }

        return $this->ledger->openIfAbsent($incident);
    }

    public function openTally(): array
    {
        return $this->ledger->openTally();
    }

    public function recordResolution(int $incidentId, Incident $resolved): bool
    {
        return $this->ledger->recordResolution($incidentId, $resolved);
    }
}
