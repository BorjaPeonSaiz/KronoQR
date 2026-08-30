<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Broadcasting;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traduce los eventos de dominio del fichaje al mensaje que ve el panel
 * (**RF-PA-01**, ADR-011, doc 02 §1.6).
 *
 * `Attendance` no sabe que esto existe: emite, y `Reporting` reacciona. Es la
 * misma via por la que `Compliance` escribe el asiento de `audit_log`, y la
 * unica que las fronteras del §1.6 conceden.
 *
 * ## Por que hay que volver a consultar
 *
 * El evento de dominio **no lleva nombre, ni departamento, ni quiosco** (regla
 * dura 21): lleva `employeeUuid`. Esos tres datos hay que resolverlos, y se
 * resuelven con **la misma consulta** que produce el listado
 * ({@see LivePresenceReader::stateOf()}). Componer el mensaje a mano a partir del
 * evento seria una segunda forma de construir la misma fila, y el dia que
 * discreparan el panel enseñaria una cosa al sondear y otra al recibir.
 *
 * Consultar despues del commit tiene ademas una ventaja: lo que sale es el
 * estado **actual**, no el que tenia el agregado cuando se emitio el evento. Si
 * dos escaneos se cruzan, gana el ultimo, que es lo que hay que enseñar.
 *
 * ## Encolado y despues del commit
 *
 * `ShouldQueue` con `$afterCommit = true`, tal y como advierte el docblock de
 * `Attendance\Infrastructure\Adapter\LaravelEventBus`: los eventos se publican
 * **dentro** de la transaccion del caso de uso, asi que un listener sincrono
 * difundiria un fichaje que todavia puede revertir —y el panel enseñaria a
 * alguien dentro que nunca entro—, ademas de meter una llamada de red en el
 * camino critico del fichaje (RNF-P-02).
 *
 * ## Y aun asi no puede romper un fichaje
 *
 * Las reglas duras 15 y 19 no admiten «normalmente no bloquea». Con una cola de
 * verdad —Horizon en produccion— este listener ya esta fuera del camino, pero la
 * cola `sync` es una configuracion legitima de una instalacion pequeña, y ahi el
 * listener corre dentro de la peticion: un Reverb caido convertiria un fichaje
 * **ya escrito** en un `500`. Por eso cualquier fallo se atrapa aqui.
 *
 * **No se reintenta, y es deliberado.** Este mensaje es una vista y caduca: un
 * reintento que reviviera cinco minutos despues la presencia de alguien
 * sobreescribiria en el panel un estado mas fresco, que es peor que no enviarlo.
 * La recuperacion correcta ya existe y es el sondeo cada 15 s del ADR-011. Lo que
 * queda es una linea de log —con `employee_uuid`, nunca con el nombre— y una
 * vista que se pone al dia sola.
 *
 * ## Toda correccion difunde, no solo la de un tramo abierto
 *
 * Decidir cuales afectan a la presencia exigiria exactamente la misma consulta
 * que resuelve la fila, asi que no ahorraria nada; y una anulacion que se
 * olvidara de difundir dejaria el panel enseñando dentro a alguien cuyo tramo se
 * acaba de anular. Se difunde siempre y gana la simplicidad.
 */
final class BroadcastPresenceChange implements ShouldQueue
{
    /**
     * Los eventos del fichaje se publican dentro de la transaccion del caso de
     * uso: sin esto, la difusion saldria sobre una escritura que aun puede
     * revertir.
     */
    public bool $afterCommit = true;

    public function __construct(
        private readonly LivePresenceReader $presence,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function clockedIn(EmployeeClockedIn $event): void
    {
        $this->announce($event->employeeUuid, $event->clockedInAt);
    }

    public function clockedOut(EmployeeClockedOut $event): void
    {
        $this->announce($event->employeeUuid, $event->clockedOutAt);
    }

    public function corrected(ShiftCorrected $event): void
    {
        // El momento de la CORRECCION, no el de las horas corregidas: lo que ha
        // cambiado en el panel ha cambiado ahora.
        $this->announce($event->employeeUuid, $event->correction->performedAt);
    }

    private function announce(string $employeeUuid, DateTimeImmutable $occurredAt): void
    {
        try {
            $entry = $this->presence->stateOf($employeeUuid);

            if ($entry === null) {
                // Ya no esta en plantilla: no hay fila que pintar. Ocurre cuando
                // se corrige el registro de alguien despues de darle de baja, que
                // es una operacion legitima (regla dura 5).
                return;
            }

            $this->events->dispatch(new PresenceUpdated($entry, $occurredAt));
        } catch (Throwable $failure) {
            // Regla dura 19: nada de la vista en vivo puede impedir un fichaje.
            // El identificador si, el nombre jamas (regla dura 21).
            $this->logger->warning('No se ha podido difundir el cambio de presencia.', [
                'employee_uuid' => $employeeUuid,
                'exception' => $failure::class,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
