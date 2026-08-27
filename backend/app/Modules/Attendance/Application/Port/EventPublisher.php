<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Publica los eventos que el agregado ha ido registrando.
 *
 * Lo implementa `Attendance/Infrastructure/Adapter` sobre el bus de Laravel
 * (tarea 1.4). El puerto existe para que el caso de uso no importe el
 * framework: `Application` no puede usar facades (doc 02 §3.5) y Deptrac lo
 * verifica.
 *
 * **Attendance no llama a nadie.** Emite, y Compliance abre las incidencias y
 * sella la auditoria, y Reporting proyecta. Es la unica forma en que el nucleo
 * puede provocar efectos en otros modulos sin depender de ellos (doc 02 §1.6).
 */
interface EventPublisher
{
    /**
     * Se llama **despues** de confirmar la transaccion: un evento publicado por
     * una escritura que luego revierte deja al panel en vivo y a la auditoria
     * contando algo que no ocurrio.
     */
    public function publish(DomainEvent ...$events): void;
}
