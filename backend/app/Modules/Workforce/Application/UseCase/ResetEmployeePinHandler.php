<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Command\ResetEmployeePinCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinMetrics;
use Illuminate\Database\ConnectionInterface;
use Random\RandomException;

/**
 * Restablecimiento del PIN (RF-ID-09, `POST /employees/{uuid}/pin/reset`).
 *
 * **Un caso de uso, una transaccion.** El hash nuevo, el borrado del bloqueo por
 * intentos y el asiento de `audit_log` son un solo hecho: si el asiento falla,
 * el PIN no cambia. Lo contrario dejaria a una persona con un PIN que nadie sabe
 * cuando se emitio ni quien lo pidio.
 *
 * **El PIN anterior se invalida por sustitucion.** No hay «desactivar»: la unica
 * copia era el hash, y al reescribirlo el PIN viejo deja de existir. Esa es la
 * razon de que se pueda prometer que se muestra una sola vez.
 *
 * **Un `uuid` desconocido responde igual que uno fuera de alcance** (regla dura
 * 17): este caso de uso devuelve `null` y la capa HTTP lo traduce a `404`. Nada
 * en la respuesta permite distinguir «no existe» de «no es tuyo», que es lo que
 * convertiria este endpoint en un comprobador de plantillas.
 *
 * **La metrica se emite despues de confirmar.** `pin_resets_total{site}` cuenta
 * restablecimientos que ocurrieron; contarlos dentro de la transaccion sumaria
 * tambien los que se revirtieron, y entonces una subida no significaria nada.
 */
final readonly class ResetEmployeePinHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private IssueEmployeePinHandler $issue,
        private PinMetrics $metrics,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @return IssuedPin|null `null` si el empleado no existe
     *
     * @throws RandomException si el sistema no puede dar aleatoriedad
     */
    public function handle(ResetEmployeePinCommand $command): ?IssuedPin
    {
        $employee = $this->employees->findByUuid($command->employeeUuid);

        if ($employee === null) {
            return null;
        }

        $issued = $this->connection->transaction(fn (): ?IssuedPin => $this->issue->handle(
            new IssueEmployeePinCommand(
                employeeUuid: $command->employeeUuid,
                siteId: $employee->siteId,
                // Siempre `reset`: aunque la ficha no tuviera PIN —una anterior a
                // RF-ID-09—, quien pulsa este boton esta restableciendo, y el
                // asiento tiene que decir lo que de verdad paso.
                reset: true,
            ),
        ));

        if ($issued instanceof IssuedPin) {
            $this->metrics->pinReset($employee->siteId);
        }

        return $issued;
    }
}
