<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use DateTimeImmutable;

/**
 * Baja de empleado (RF-GP-03, RN-14).
 *
 * **Desactivacion logica, nunca borrado** (regla dura 5). Ni esta clase ni el
 * repositorio tienen un `delete`: la ficha cambia de estado y gana su fecha de
 * cese, y todo lo demas —tramos, jornadas, escaneos— sigue exactamente donde
 * estaba. El registro horario se conserva cuatro anos (RL-02) y una inspeccion
 * puede pedir el de alguien que ya no trabaja en el hotel.
 *
 * **La fecha de cese no puede ser posterior a hoy** (RN-14, 2.2.0). «Hoy» es la
 * fecha civil **del centro** (`sites.timezone`, ADR-040) en el instante en que
 * el servidor recibe la baja, y se resuelve aqui con el puerto `Clock`: nunca la
 * fecha UTC ni `APP_TIMEZONE`. Sin centro no hay «hoy» que resolver, y la baja
 * falla con {@see InstallationSiteMissing} (`409`) en lugar de caer a UTC. La
 * regla la aplica el dominio ({@see Employee::offboard()}); la validacion de la
 * peticion solo conoce la fecha UTC y no la comprueba.
 *
 * **Bajo el candado de la cadena y con la fila bloqueada** (ADR-046). La lectura
 * es `findForUpdate()` dentro de `withChainLock()`: una modificacion o una
 * importacion simultanea esperan a que esta baja confirme y despues la ven, en
 * vez de reescribir una ficha leida antes y deshacerla. La escritura es
 * `saveTermination()`, que solo toca `status` y `terminated_at` y lleva el
 * predicado `status <> 'terminated'`.
 *
 * **Una transaccion con todo lo que la baja arrastra** (N1, AUD-2). El evento
 * se publica dentro: `Identity` revoca la credencial y cierra el portal
 * (RN-14) y `Compliance` escribe el asiento `employee.offboarded`, los dos en
 * listeners sincronos y en el orden de ADR-046 (cadena → ficha → tarjetas). Si
 * cualquiera falla, la baja no se confirma: una baja sin traza, o una persona de
 * baja con la tarjeta aun activa, es peor que una baja que hay que repetir.
 */
final readonly class OffboardEmployeeHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * @throws EmployeeAlreadyTerminated cuando ya estaba de baja
     * @throws InvalidEmploymentPeriod cuando el cese es anterior al alta, posterior a hoy, o el alta aun no llego
     * @throws InstallationSiteMissing antes de la puesta en marcha: sin centro no hay «hoy» (R-4)
     */
    public function handle(OffboardEmployeeCommand $command): ?Employee
    {
        $site = $this->sites->installationSite();

        if (! $site instanceof Site) {
            throw InstallationSiteMissing::make();
        }

        $receivedAt = $this->clock->now();

        // La fecha civil del centro en el instante en que llega la baja. Se
        // resuelve una vez, fuera de la cadena: esperar al candado no puede
        // cambiar que dia era cuando se pidio.
        $today = $receivedAt->setTimezone($site->timezone->toDateTimeZone());

        return $this->serialized->withChainLock(function () use ($command, $today, $receivedAt): ?Employee {
            $employee = $this->employees->findForUpdate($command->uuid);

            if ($employee === null) {
                return null;
            }

            $terminated = $employee->offboard(new DateTimeImmutable($command->terminatedAt), $today);

            $this->employees->saveTermination($terminated);

            $this->events->publish(new EmployeeOffboarded(
                employeeUuid: $terminated->uuid,
                terminatedOn: $command->terminatedAt,
                reason: $command->reason,
                occurredAt: $receivedAt,
            ));

            return $terminated;
        });
    }
}
