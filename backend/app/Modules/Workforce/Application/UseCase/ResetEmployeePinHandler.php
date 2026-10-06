<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Command\ResetEmployeePinCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinMetrics;
use Random\RandomException;

/**
 * Restablecimiento del PIN (RF-ID-09, `POST /employees/{uuid}/pin/reset`).
 *
 * **Un caso de uso, una transaccion.** El hash nuevo, el borrado del bloqueo por
 * intentos y el asiento de `audit_log` son un solo hecho: si el asiento falla,
 * el PIN no cambia. Lo contrario dejaria a una persona con un PIN que nadie sabe
 * cuando se emitio ni quien lo pidio. La transaccion la abre la emision, con la
 * cadena tomada (ADR-046); el hash se calcula aqui antes, fuera de ella (A-3).
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

        // EL bcrypt, ANTES DE LA CADENA (ADR-046 §1.1 punto 5, A-3). Dentro de la
        // emision correria con el candado de `audit_log` tomado y congelaria los
        // fichajes del hotel unos 160 ms por restablecimiento.
        $material = $this->issue->freshMaterial();

        // La emision abre su propia transaccion con la cadena tomada: hash
        // nuevo, desbloqueo y asiento son un solo hecho.
        $issued = $this->issue->handle(new IssueEmployeePinCommand(
            employeeUuid: $command->employeeUuid,
            siteId: $employee->siteId,
            // Se pide sustituir, y la emision decide con la cadena tomada si
            // de verdad habia algo que sustituir: sobre un PIN pendiente —toda
            // persona importada (RF-GP-05)— esta es su primera emision y el
            // asiento dice `pin.issued`, porque es lo que de verdad paso.
            reset: true,
            material: $material,
        ));

        // Solo cuenta lo que fue un restablecimiento. La primera emision de un
        // pendiente es una entrega de tarjeta, y sumarla haria que una
        // temporada de contrataciones importadas se pareciera a un problema de
        // entrega de PIN.
        if ($issued instanceof IssuedPin && $issued->replacedPrevious) {
            $this->metrics->pinReset($employee->siteId);
        }

        return $issued;
    }
}
