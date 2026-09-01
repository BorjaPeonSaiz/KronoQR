<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Listener;

use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El observador de los limites del plan (**ADR-028**, RF-PD-04).
 *
 * ## Es un observador y no un guardian, y aqui es donde se demuestra
 *
 * Escucha altas **ya consumadas**. Cuando `EmployeeHired` llega, la persona esta
 * en la plantilla, tiene su ficha y puede recibir su tarjeta; cuando llega
 * `DeviceTokenIssued`, el quiosco ya tiene su token y puede registrar fichajes.
 * Este listener no puede impedir ninguna de las dos cosas: no participa en la
 * decision, se entera despues.
 *
 * Eso es exactamente lo que ADR-028 exige, y el motivo esta escrito alli:
 * bloquear el alta deja a una persona **trabajando sin registro horario**
 * —infraccion del art. 34.9 ET imputable al cliente y causada por el producto— y
 * bloquear el emparejamiento deja un centro sin punto de fichaje el dia que se
 * avería el quiosco. La palanca comercial es el contrato, no el software.
 *
 * ## Nunca lanza, y corre DESPUES de que el alta confirme
 *
 * Los dos eventos se publican **dentro** de la transaccion del alta, porque su
 * primer suscriptor es el asiento de auditoria del alta, que si tiene que poder
 * impedirla (ADR-027). Este observador es lo contrario, y por eso difiere su
 * trabajo con `afterCommit`:
 *
 *  - **Contar dentro seria contar de mas** si la transaccion acabara
 *    revirtiendose: quedaria un asiento de exceso por un alta que nunca existio.
 *  - **Y fallar dentro seria fatal.** Una consulta que falla dentro de una
 *    transaccion de PostgreSQL la deja abortada: a partir de ahi todo error, y
 *    el `try/catch` de mas abajo no la salvaria. El alta se perderia por culpa
 *    de un contador comercial, que es el bloqueo de ADR-028 por la puerta de
 *    atras.
 *
 * `ShouldQueue` **no**: el asiento que produce es la evidencia comercial de
 * ADR-028 y no puede depender de que la cola este viva.
 *
 * Y aun asi, todo el cuerpo va bajo `try`: si la licencia no se puede leer, o
 * Redis no responde, o el asiento falla, **el alta ya esta hecha y no se
 * deshace**. Lo unico que se pierde es la evidencia de este exceso concreto.
 *
 * ## La rotacion no cuenta
 *
 * `DeviceTokenIssued` se publica tambien cuando un token se renueva solo al 80 %
 * de su vida (RF-ID-04). Eso ocurre muchas veces y **no da de alta ningun
 * dispositivo**: contarlo produciria un asiento de exceso cada tres meses por
 * cada quiosco, sin que nada hubiera cambiado, y el trail dejaria de servir para
 * lo que existe.
 *
 * ## Sin `Product -> Workforce` ni `Product -> Identity`
 *
 * Este fichero importa **eventos de dominio** y nada mas: dos objetos de valor
 * inmutables sin comportamiento. Es la misma via —y la misma concesion de
 * Deptrac— por la que `Compliance` sella el alta de un empleado y `Reporting`
 * difunde la presencia. Ningun caso de uso de `Workforce` o de `Identity`
 * conoce la licencia, que es la otra mitad de la promesa de ADR-028.
 */
final readonly class ObservePlanLimits
{
    public function __construct(
        private RecordPlanUsageHandler $usage,
        private LoggerInterface $logger,
        /**
         * La conexion concreta y no `ConnectionInterface`: `afterCommit()` lo
         * declara `Connection`, no la interfaz. Mismo motivo que en
         * `CachedSettingsRepository` (tarea 5.1).
         */
        private Connection $connection,
    ) {}

    public function onEmployeeHired(EmployeeHired $event): void
    {
        $this->observe(PlanLimit::Employees, null);
    }

    public function onDeviceTokenIssued(DeviceTokenIssued $event): void
    {
        if ($event->rotation) {
            return;
        }

        $this->observe(PlanLimit::Devices, $event->actorUserId);
    }

    private function observe(PlanLimit $limit, ?int $actorUserId): void
    {
        // `afterCommit` ejecuta en el acto si no hay transaccion abierta, y
        // espera al `COMMIT` de la del alta si la hay. Es lo que separa este
        // trabajo del exito o el fracaso del alta, en las dos direcciones.
        $this->connection->afterCommit(function () use ($limit, $actorUserId): void {
            try {
                $this->usage->handle($limit, $actorUserId);
            } catch (Throwable $exception) {
                // El alta ya ocurrio y esta confirmada. Lo unico que se pierde
                // es la evidencia comercial de este exceso concreto, y eso vale
                // infinitamente menos que dejar sin dar de alta a alguien que
                // empieza a trabajar hoy (ADR-028).
                $this->logger->warning('product.plan_limit_observation_failed', [
                    'limit' => $limit->value,
                    'reason' => $exception::class,
                ]);
            }
        });
    }
}
