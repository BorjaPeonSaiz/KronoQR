<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Publica los eventos de plantilla hacia el resto del sistema.
 *
 * **Es el enganche de todo lo que el alta y la baja provocan fuera de este
 * modulo**: la revocacion de credencial de `Identity` (tarea 1.5), el asiento de
 * `audit_log` de `Compliance` (tarea 1.14) y las proyecciones de `Reporting`. El
 * puerto existe para que el caso de uso no importe el bus del framework —
 * `Application` no usa facades (doc 02 §3.5)— y para que una prueba pueda
 * comprobar que se publico lo que se tenia que publicar sin arrancar nada.
 *
 * Se llama **despues** de confirmar la transaccion, por el mismo motivo que en
 * el nucleo: un evento de un alta que luego revierte deja al panel y a la
 * auditoria contando algo que no ocurrio.
 */
interface WorkforceEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
