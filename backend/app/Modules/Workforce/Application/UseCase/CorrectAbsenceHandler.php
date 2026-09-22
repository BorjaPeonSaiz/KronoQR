<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\CorrectAbsenceCommand;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\AbsenceCorrected;
use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\Exception\OverlappingAbsence;
use App\Modules\Workforce\Domain\Model\Absence;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Corrige una ausencia creando una **version nueva** (**RN-13**, regla dura 5).
 *
 * ## Nada se sobrescribe
 *
 * Se inserta una fila con `version + 1`, `uuid` propio y `supersedes_id`
 * apuntando a la anterior, y sobre la anterior se escriben **solo** `status` y
 * `superseded_by_id`. Su tipo, sus fechas, su nota, su autor y su momento se
 * quedan como estaban. Es el mismo patron que las correcciones de
 * `shift_entries` (ADR-026, ADR-035), y por la misma razon: lo que fue verdad
 * sigue siendolo.
 *
 * ## Las dos escrituras son una sola operacion del repositorio
 *
 * Insertar la version nueva y cerrar la anterior no caben en ningun orden por
 * separado: la vieja tiene que apuntar a una fila que todavia no existe, y las
 * dos `active` a la vez chocarian con `absences_no_overlap`. La salida es
 * diferir esa restriccion entre las dos sentencias, que es un detalle de
 * PostgreSQL y vive donde le corresponde —`AbsenceRepository::supersedeWith()`—.
 * Aqui solo se dice que sustituir **es un hecho**, no dos escrituras sueltas que
 * puedan quedar a medias.
 *
 * ## El ANTES se lee aqui, para el asiento
 *
 * `previousType`, `previousStartsOn` y `previousEndsOn` salen de la version que
 * se sustituye y viajan en el evento. Sin ellos, responder «¿quien alargo esta
 * baja y de cuanto a cuanto?» obligaria a reconstruir la cadena de versiones
 * desde la primera.
 */
final readonly class CorrectAbsenceHandler
{
    public function __construct(
        private AbsenceRepository $absences,
        private EmployeeRepository $employees,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * `null` si esa ausencia no existe: es un `404` y lo traduce el controlador.
     *
     * @throws AbsenceNotActive si la version de la ruta ya fue corregida o anulada
     * @throws InvalidAbsencePeriod si el periodo nuevo no toca la relacion laboral
     * @throws OverlappingAbsence si el periodo nuevo pisa otra ausencia activa
     */
    public function handle(CorrectAbsenceCommand $command): ?Absence
    {
        return $this->connection->transaction(function () use ($command): ?Absence {
            // LA LECTURA VA DENTRO DE LA TRANSACCION Y CON CANDADO. Leerla fuera
            // —como se hacia antes de la revision— dejaba que dos `PATCH`
            // simultaneos sobre la misma version la vieran los dos `active`, la
            // corrigieran los dos y dejaran DOS filas `version = 2` colgando de
            // la misma v1: el historial bifurcado, dos asientos y el informe
            // contando los dias dos veces. Con el candado, la segunda peticion
            // espera, relee la fila ya en `superseded` y responde `409`.
            $previous = $this->absences->findForUpdate($command->absenceUuid);

            if ($previous === null) {
                return null;
            }

            // Si ya no es la vigente, `409`. Lo lanza el propio modelo desde
            // `correctedWith()`, pero decirlo aqui da el estado real en el
            // mensaje en lugar del que tendria despues.
            if (! $previous->isActive()) {
                throw AbsenceNotActive::forAbsence($previous->uuid, $previous->status->value);
            }

            $corrected = $previous->correctedWith(
                uuid: Str::uuid7()->toString(),
                type: $command->type,
                startsOn: $command->startsOn === null ? null : RegisterAbsenceHandler::asDate($command->startsOn),
                endsOn: $command->endsOn === null ? null : RegisterAbsenceHandler::asDate($command->endsOn),
                note: $command->note,
                noteGiven: $command->noteGiven,
                reason: $command->reason,
            );

            // Dentro tambien: es una comprobacion, no una escritura, y lanzarla
            // aqui solo deshace una transaccion que no habia escrito nada.
            $this->assertWithinEmployment($corrected);

            // Las dos mitades en una sola operacion. El orden y la restriccion
            // diferida son del adaptador: aqui solo se dice que sustituir es un
            // hecho, no dos escrituras sueltas que puedan quedar a medias.
            $stored = $this->absences->supersedeWith(
                // La transicion la declara el DOMINIO —`supersededBy()` es lo que
                // sabe que lo unico que cambia de la version anterior son su
                // estado y su puntero (regla dura 5)— y el repositorio la
                // escribe. Pasar la fila cruda dejaria esa regla escrita solo en
                // el adaptador.
                $previous->supersededBy($corrected->uuid),
                $corrected,
                $command->correctedByUserId,
            );

            $this->events->publish(new AbsenceCorrected(
                absenceUuid: $stored->uuid,
                supersedesUuid: $previous->uuid,
                employeeUuid: $stored->employeeUuid,
                type: $stored->type->value,
                startsOn: $stored->isoStartsOn(),
                endsOn: $stored->isoEndsOn(),
                version: $stored->version,
                hasNote: $stored->hasNote(),
                // El ANTES completo: es lo que convierte el asiento en algo
                // reconstruible sin recorrer la cadena entera.
                previousType: $previous->type->value,
                previousStartsOn: $previous->isoStartsOn(),
                previousEndsOn: $previous->isoEndsOn(),
                reason: $command->reason,
                occurredAt: $this->clock->now(),
            ));

            return $stored;
        });
    }

    /**
     * La version nueva tambien tiene que tocar la relacion laboral.
     *
     * Se comprueba aunque la anterior ya lo cumpliera: corregir puede mover las
     * fechas a un periodo en el que esa persona no estaba de alta, y eso no es
     * menos falso por venir de una correccion.
     *
     * Si la ficha ha desaparecido —imposible, porque la clave ajena es
     * `RESTRICT`— no se inventa una respuesta: se deja pasar y la invariante que
     * manda sigue siendo la del esquema.
     *
     * @throws InvalidAbsencePeriod
     */
    private function assertWithinEmployment(Absence $absence): void
    {
        $employee = $this->employees->findByUuid($absence->employeeUuid);

        if ($employee === null) {
            return;
        }

        if (! $absence->fallsWithinEmployment($employee->hiredAt, $employee->terminatedAt)) {
            throw InvalidAbsencePeriod::isOutsideEmployment($absence->isoStartsOn(), $absence->isoEndsOn());
        }
    }
}
