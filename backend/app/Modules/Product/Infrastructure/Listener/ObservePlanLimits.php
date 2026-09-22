<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Listener;

use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Modules\Workforce\Domain\Event\EmployeesImported;
use Closure;
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
 * ## Una evaluacion por OPERACION: la importacion cuenta una vez
 *
 * `EmployeeHired` llega **por fila**, y la importacion de plantilla (RF-GP-05)
 * trae hasta 500 de golpe. Contar en cada una escribia un asiento
 * `license.plan_exceeded` por fila en exceso, todos bajo el
 * `pg_advisory_xact_lock` global de `audit_log` (ADR-010) —el mismo candado por
 * el que pasa cada fichaje del hotel—, y ademas con la cifra mal: como la
 * evaluacion se difiere al `afterCommit` del lote entero, **todas** las filas
 * veian el recuento final y ninguna se reconocia como el cruce del umbral.
 *
 * Desde la 3.8 (H-04) las altas que vienen de una importacion se ignoran aqui y
 * el conteo lo dispara `EmployeesImported`, una sola vez, con el numero de altas
 * ya confirmadas. El sintoma que esto evitaba no era un bloqueo —los quioscos
 * encolan, regla dura 19— sino «el quiosco va lento» mientras RRHH importa.
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
        // El alta de una carga masiva NO se cuenta aqui: la cuenta el evento del
        // lote, una sola vez y con el recuento ya confirmado (mas abajo). Con la
        // cuenta por fila, un hotel con plan de 80 que importara 300 personas
        // escribia trescientos asientos `license.plan_exceeded` casi identicos
        // —todas las filas ven el recuento FINAL, porque la evaluacion se difiere
        // al `afterCommit` del lote entero—, y los trescientos pasan por el
        // `pg_advisory_xact_lock` global de `audit_log` (ADR-010), que es el
        // mismo por el que pasa cada fichaje del hotel. H-04 de la revision de
        // la 3.8.
        //
        // No se pierde evidencia: el asiento del lote lleva el recuento final, lo
        // contratado y cuantas de esas altas quedaron por encima del plan.
        if ($event->viaImport) {
            return;
        }

        $this->observe(PlanLimit::Employees, null);
    }

    /**
     * La importacion de plantilla, contada **una vez** (RF-GP-05, H-04).
     *
     * `created` y no las filas del fichero: las lineas rechazadas no dan de alta
     * a nadie y no ocupan plaza del plan. Una importacion que solo modifica
     * fichas no cambia el recuento y no evalua nada.
     *
     * Sin `actorUserId`, igual que el alta individual: el asiento toma el actor
     * de la peticion en curso (`CurrentAuditContext`), que es quien subio el
     * fichero.
     */
    public function onEmployeesImported(EmployeesImported $event): void
    {
        $created = $event->created;

        if ($created < 1) {
            return;
        }

        $this->deferred(
            PlanLimit::Employees,
            fn () => $this->usage->handleBatch(PlanLimit::Employees, $created, null),
        );
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
        $this->deferred($limit, fn () => $this->usage->handle($limit, $actorUserId));
    }

    /**
     * El conteo, diferido al `COMMIT` y bajo `try`.
     *
     * Es la forma de los dos caminos —el alta de una en una y el lote— y esta
     * escrito una sola vez a proposito: las dos garantias que lo envuelven son
     * las que hacen que este observador no pueda convertirse en un guardian, y
     * una copia que se dejara el `try` seria ADR-028 incumplido por descuido.
     *
     * @param  Closure(): void  $count
     */
    private function deferred(PlanLimit $limit, Closure $count): void
    {
        // `afterCommit` ejecuta en el acto si no hay transaccion abierta, y
        // espera al `COMMIT` de la del alta si la hay. Es lo que separa este
        // trabajo del exito o el fracaso del alta, en las dos direcciones.
        $this->connection->afterCommit(function () use ($limit, $count): void {
            try {
                $count();
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
