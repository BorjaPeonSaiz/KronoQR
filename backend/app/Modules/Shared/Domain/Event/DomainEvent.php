<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Event;

use DateTimeImmutable;

/**
 * Contrato de todo evento de dominio del sistema (doc 02 §1.6: Shared es el
 * sitio de «los contratos de eventos»).
 *
 * Vive en Shared y no en Attendance porque la comunicacion entre modulos ocurre
 * por evento: Compliance escucha los de Attendance, Reporting proyecta los de
 * todos, y ninguno de los dos puede importar el Domain del otro (§1.6). Un
 * contrato comun es lo que permite tipar EventPublisher y los listeners sin
 * abrir esa frontera.
 *
 * Dos metodos y ni uno mas:
 *
 * - `occurredAt()` es el instante **del hecho**, no el de su publicacion. Lo
 *   recibe el evento ya resuelto —en un fichaje offline es el `occurred_at` del
 *   dispositivo, que puede llevar horas de retraso (regla dura 9, RF-AT-09)—,
 *   y por eso ninguna implementacion lo calcula: eso seria leer el reloj desde
 *   el dominio (regla dura 2).
 * - `eventName()` da el nombre estable que usan `audit_log.action` (RS-07) y las
 *   metricas de negocio. Se escribe una vez aqui y no se deriva del nombre de la
 *   clase: renombrar una clase no puede cambiar lo que ya esta escrito en un
 *   registro con valor legal.
 */
interface DomainEvent
{
    /**
     * Nombre estable del evento, en `modulo.hecho_en_pasado`.
     */
    public function eventName(): string;

    /**
     * Instante real del hecho, siempre en UTC (regla dura 3).
     */
    public function occurredAt(): DateTimeImmutable;
}
