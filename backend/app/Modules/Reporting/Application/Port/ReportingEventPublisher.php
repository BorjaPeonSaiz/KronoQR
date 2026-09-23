<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Publica los eventos de `Reporting` hacia el resto del sistema (**RF-IN-06**).
 *
 * **Es el enganche del asiento de `audit_log`.** El §1.6 no concede la arista
 * `Reporting -> Compliance`, asi que el caso de uso que pide, genera o entrega
 * un informe en diferido no puede llamar a `RecordAuditEntry`: publica el hecho
 * y el listener `RecordReportExportLifecycle` de `Compliance/Infrastructure` lo
 * sella. Es la misma via por la que se auditan el alta de un empleado, el cambio
 * de un ajuste y la exportacion integra.
 *
 * **No sustituye a `PersonalDataAccessLog`.** Aquel es el puerto de la
 * divulgacion (RS-05) y lo usa el informe **sincrono**; este transporta los tres
 * hechos del ciclo de vida del fichero. Son preguntas distintas: «quien consulto
 * estas horas» y «quien pidio, genero y se llevo este fichero».
 *
 * **Se llama DENTRO de la transaccion**, como el de `Product` y por lo mismo: el
 * unico suscriptor es el asiento, que es sincrono y **tiene que poder impedir el
 * hecho si falla** (regla dura 6, ADR-027). Un fichero con las horas de la
 * plantilla entregado sin traza es peor que una descarga que no llega a
 * ocurrir.
 */
interface ReportingEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
