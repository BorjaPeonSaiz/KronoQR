<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Publica los eventos de dominio de `Kiosk` hacia el resto del sistema.
 *
 * **Es el enganche del asiento de `audit_log`.** El §1.6 no concede la arista
 * `Kiosk -> Compliance`, asi que el caso de uso que da de alta un quiosco no
 * puede llamar a `RecordAuditEntry`: publica el hecho y un listener de
 * `Compliance/Infrastructure` lo sella. Es la misma via por la que se auditan el
 * alta de un empleado, la emision de una credencial y el cambio de configuracion.
 *
 * El puerto existe ademas para que el caso de uso no importe el bus del framework
 * —`Application` no usa facades (doc 02 §3.5, verificado por Deptrac)— y para que
 * una prueba pueda comprobar que se publico lo que se tenia que publicar.
 *
 * **Se llama DENTRO de la transaccion.** El unico suscriptor es el asiento de
 * auditoria, que es sincrono y **tiene que poder impedir el alta si falla**
 * (regla dura 6, ADR-027): un quiosco dado de alta sin traza es peor que un alta
 * que no llega a producirse, porque la segunda se repite y la primera no se
 * descubre.
 */
interface KioskEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
