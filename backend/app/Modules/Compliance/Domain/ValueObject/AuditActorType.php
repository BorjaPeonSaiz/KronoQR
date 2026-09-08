<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Quien actua. En la Fase 1 son tres y no cuatro por accidente (doc 02 §11,
 * nota de ADR-032): el RBAC completo llega en la 2.1, pero identificar al actor
 * ya es posible con los tokens de dispositivo de la 1.5 y la autenticacion de
 * gestion minima de la 1.6.
 */
enum AuditActorType: string
{
    /** Persona autenticada en el panel o en el portal. `actor_id` es `users.id`. */
    case User = 'user';

    /** Quiosco emparejado. `actor_id` es `devices.id` (tarea 1.5). */
    case Device = 'device';

    /** Scheduler, colas, comandos de consola. Sin `actor_id`. */
    case System = 'system';

    /**
     * Rol de mantenimiento de base de datos (ADR-027). Es el unico que puede
     * soltar una particion, y **no aparece en el `.env` de la aplicacion**: si
     * una entrada con este actor la escribiera el proceso web, seria un
     * hallazgo, no un dato.
     */
    case Maintenance = 'maintenance';

    /**
     * Una **concesion de acceso de soporte** (RF-PD-11, RL-18, ADR-020, tarea
     * 5.9). `actor_id` es `support_grants.id`.
     *
     * **El quinto actor, y el unico que no vive en la instalacion.** Detras hay
     * una persona del fabricante, pero **no tiene cuenta aqui y no puede
     * tenerla**: ADR-020 existe justo para eso, y la regla dura 16 lo dice sin
     * matices. Lo que si existe es la concesion que el cliente firmo, con su
     * motivo, su alcance y su caducidad, y es ella la que actua.
     *
     * **Por que no se reutiliza `User`.** Porque entonces habria que fabricar
     * una cuenta del fabricante para poder auditarla, que es exactamente la
     * «cuenta de soporte permanente» que ADR-020 descarta en su tabla de
     * alternativas. Y porque `actor_id` apuntando a la concesion es lo que
     * permite responder «¿que hizo el acceso que concedi el martes por la
     * incidencia #123?» con un filtro por columna indexada, en lugar de cruzar
     * el trail con las fechas de una tabla aparte.
     *
     * **Por que no `System`.** Ese actor significa «no hay nadie detras»
     * —scheduler, colas, consola—, y aqui hay alguien detras: alguien ajeno a la
     * organizacion del cliente. Confundir los dos borraria la unica distincion
     * que importa en este trail.
     *
     * El `CHECK` `audit_log_chk_actor_type` lo admite desde la migracion
     * `2026_09_11_100100_allow_support_grant_audit_actor`.
     */
    case SupportGrant = 'support_grant';
}
