<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\VoidAbsenceCommand;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\AbsenceVoided;
use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\Model\Absence;
use Illuminate\Database\ConnectionInterface;

/**
 * Anula una ausencia: declara que **no ocurrio** (**RF-GP-04**, regla dura 5).
 *
 * ## No borra y no versiona
 *
 * La fila se queda en la tabla con su tipo, sus fechas, su nota, su autor y su
 * momento; lo unico que cambia es que sale del conjunto vigente y gana autor,
 * momento y motivo de anulacion. **No crea version** —no hay una version
 * posterior de un hecho que no paso— que es toda la diferencia con corregir. Es
 * el mismo criterio que `POST /shift-entries/{uuid}/void` (ADR-026).
 *
 * Al salir del conjunto vigente libera esos dias: a partir de ahi se puede
 * registrar otra ausencia que los cubra, porque `absences_no_overlap` solo mira
 * las activas.
 *
 * ## El instante sale del reloj inyectado
 *
 * Nunca `now()` (regla dura 2). Sin eso, una prueba con el reloj congelado
 * escribiria en la fila una fecha distinta de la del asiento que la acompaña.
 */
final readonly class VoidAbsenceHandler
{
    public function __construct(
        private AbsenceRepository $absences,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * `null` si esa ausencia no existe: es un `404` y lo traduce el controlador.
     *
     * @throws AbsenceNotActive si ya estaba anulada o sustituida
     */
    public function handle(VoidAbsenceCommand $command): ?Absence
    {
        return $this->connection->transaction(function () use ($command): ?Absence {
            // LA LECTURA VA DENTRO DE LA TRANSACCION Y CON CANDADO, por lo mismo
            // que en la correccion: sin el, anular y corregir en paralelo
            // llegaban las dos a escribir y la segunda reventaba contra
            // `absences_chk_superseded_consistency` con un `500` que no decia
            // nada. Con el candado, la segunda espera, relee y responde `409`.
            $absence = $this->absences->findForUpdate($command->absenceUuid);

            if ($absence === null) {
                return null;
            }

            // `voidedWith()` lo comprobaria igual; decirlo aqui da el estado real
            // en el mensaje.
            if (! $absence->isActive()) {
                throw AbsenceNotActive::forAbsence($absence->uuid, $absence->status->value);
            }

            $voided = $absence->voidedWith($this->clock->now(), $command->reason);

            $this->absences->markVoided($voided, $command->voidedByUserId);

            $this->events->publish(new AbsenceVoided(
                absenceUuid: $voided->uuid,
                employeeUuid: $voided->employeeUuid,
                type: $voided->type->value,
                startsOn: $voided->isoStartsOn(),
                endsOn: $voided->isoEndsOn(),
                version: $voided->version,
                hasNote: $voided->hasNote(),
                reason: $command->reason,
                occurredAt: $this->clock->now(),
            ));

            return $voided;
        });
    }
}
