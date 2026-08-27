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
}
