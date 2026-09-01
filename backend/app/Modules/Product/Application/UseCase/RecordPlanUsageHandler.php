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
 * `firstCrossing` es `actual === contratado + 1`. No hace falta recordar nada
 * entre ejecuciones, y ademas es lo correcto cuando el exceso se corrige y se
 * vuelve a producir: son dos cruces y los dos merecen su asiento con fecha.
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

    public function handle(PlanLimit $limit, ?int $actorUserId = null): void
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
            firstCrossing: $usage->excess() === 1,
            licenseId: $license->licenseId,
            actorUserId: $actorUserId,
            occurredAt: $this->clock->now(),
        ));
    }
}
