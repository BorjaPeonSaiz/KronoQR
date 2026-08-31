<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Publica los eventos de `Product` hacia el resto del sistema.
 *
 * **Es el enganche del asiento de `audit_log`.** El §1.6 no concede la arista
 * `Product -> Compliance`, asi que el caso de uso que cambia la configuracion no
 * puede llamar a `RecordAuditEntry`: publica el hecho y un listener de
 * `Compliance/Infrastructure` lo sella. Es la misma via por la que se auditan el
 * alta de un empleado, la emision de una credencial y el registro de un
 * contrato.
 *
 * El puerto existe ademas para que el caso de uso no importe el bus del
 * framework —`Application` no usa facades (doc 02 §3.5, verificado por
 * Deptrac)— y para que una prueba pueda comprobar que se publico lo que se tenia
 * que publicar sin arrancar nada.
 *
 * **Se llama DENTRO de la transaccion**, al contrario que el de `Workforce`, que
 * publica despues de confirmar. La diferencia no es un descuido: aqui el unico
 * suscriptor es el asiento de auditoria, que es sincrono y **tiene que poder
 * impedir el cambio si falla** (regla dura 6, ADR-027). Un umbral de calculo
 * cambiado sin traza es peor que un cambio que no llega a producirse, porque el
 * segundo se vuelve a intentar y el primero no se descubre.
 */
interface ProductEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
