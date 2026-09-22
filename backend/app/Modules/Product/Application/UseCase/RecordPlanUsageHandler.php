<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\LicenseMetrics;
use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\PlanLimitExceeded;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\PlanUsage;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Cuenta lo que hay y, si supera el plan, deja constancia (**ADR-028**).
 *
 * ## Observador, no guardian
 *
 * Se invoca **despues** de un alta consumada, desde un listener que escucha
 * `EmployeeHired` y `DeviceTokenIssued`. No devuelve nada que se pueda
 * interpretar como permiso, no lanza, y ninguna ruta del producto mira su
 * resultado antes de responder. Esa es la garantia estructural de la promesa de
 * ADR-028: *«ninguna ruta del producto puede devolver un error de licencia al
 * dar de alta a una persona ni al emparejar un dispositivo»*.
 *
 * Si este metodo fallara —Redis caido, `license` ilegible— el alta ya se hizo y
 * no se deshace. Por eso el listener lo llama fuera de la transaccion del alta y
 * atrapa cualquier `Throwable`: un contador comercial no puede tumbar el alta de
 * un camarero en temporada alta.
 *
 * ## Los tres efectos de ADR-028
 *
 * 1. **Asiento en `audit_log`** al cruzar y en cada alta posterior en exceso.
 *    Es lo que da la fecha exacta desde la que el cliente opera fuera de plan, y
 *    lo unico que sostiene una reclamacion comercial. Lo escribe el listener de
 *    `Compliance` a partir del evento que se publica aqui.
 * 2. **Aviso persistente en el panel**: sale de `GET /api/v1/license`, que
 *    calcula las mismas cifras cuando se le pregunta.
 * 3. **Cifra en `license:show`**: idem.
 *
 * ## El cruce se deduce, no se guarda
 *
 * `firstCrossing` es «antes de esta operacion se cabia en el plan»
 * ({@see PlanUsage::crossedBy()}). No hace falta recordar nada entre
 * ejecuciones, y ademas es lo correcto cuando el exceso se corrige y se vuelve a
 * producir: son dos cruces y los dos merecen su asiento con fecha.
 *
 * ## Una evaluacion por OPERACION, no por unidad (H-04, tarea 3.8)
 *
 * {@see self::handle()} es el alta de una en una y {@see self::handleBatch()} la
 * importacion de plantilla (RF-GP-05), que entra **entera** con una sola
 * evaluacion. Hasta la 3.8 el lote se evaluaba fila a fila y un hotel con plan
 * de 80 que importara 300 personas escribia trescientos asientos casi
 * identicos, todos bajo el `pg_advisory_xact_lock` global de `audit_log`
 * (ADR-010) —el mismo candado por el que pasa cada fichaje del hotel—, y con
 * `firstCrossing` en falso en todos, porque cada fila veia ya el recuento final.
 *
 * No bloqueaba a nadie: los quioscos encolan (regla dura 19). Era carga
 * evitable en el unico candado que el producto no puede permitirse
 * congestionar, y una evidencia comercial peor de la que se podia escribir.
 */
final readonly class RecordPlanUsageHandler
{
    public function __construct(
        private GetLicenseStatusHandler $status,
        private PlanUsageCounter $counter,
        private ProductEventPublisher $events,
        private LicenseMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * El alta de una en una: `POST /api/v1/employees` y el emparejamiento de un
     * quiosco. Una unidad, una evaluacion.
     */
    public function handle(PlanLimit $limit, ?int $actorUserId = null): void
    {
        $this->record($limit, added: 1, actorUserId: $actorUserId);
    }

    /**
     * La operacion que añade **varias unidades de golpe**: hoy, la importacion
     * de plantilla (RF-GP-05).
     *
     * Se llama **una vez, con el lote ya confirmado**, y por eso `$added` es lo
     * que de verdad entro —las filas `create` del informe—, no lo que traia el
     * fichero: las lineas rechazadas no dan de alta a nadie y no pueden ocupar
     * plaza del plan.
     *
     * Sin altas no hay nada que evaluar. Una importacion que solo modifica fichas
     * no cambia el recuento, y escribir un asiento de exceso por ella diria que
     * alguien se paso del plan el dia que corrigio cuarenta apellidos.
     */
    public function handleBatch(PlanLimit $limit, int $added, ?int $actorUserId = null): void
    {
        if ($added < 1) {
            return;
        }

        $this->record($limit, added: $added, actorUserId: $actorUserId);
    }

    private function record(PlanLimit $limit, int $added, ?int $actorUserId): void
    {
        $license = $this->status->handle()->license;

        // Sin licencia verificada no hay plan contra el que comparar, y no se
        // inventa uno: una instalacion recien puesta en marcha no esta en
        // exceso, esta sin activar. El banner de «sin licencia» ya lo dice.
        if (! $license instanceof License) {
            return;
        }

        $usage = new PlanUsage($limit, $license->limits->contractedFor($limit), $this->counter->count($limit));

        if (! $usage->isExceeded()) {
            return;
        }

        $this->metrics->limitExceeded($limit);

        $this->events->publish(new PlanLimitExceeded(
            limit: $limit->value,
            contracted: (int) $usage->contracted,
            reached: $usage->actual,
            firstCrossing: $usage->crossedBy($added),
            addedInExcess: $usage->excessAmong($added),
            licenseId: $license->licenseId,
            actorUserId: $actorUserId,
            occurredAt: $this->clock->now(),
        ));
    }
}
