<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

/**
 * Lo que la tarea hizo con una baja programada vencida (ADR-062 §4).
 *
 * Los tres primeros son los valores de la etiqueta `outcome` de
 * `kronoqr_offboardings_applied_total`. `Skipped` no se mide: es la baja que
 * otra escritura anulo, reprogramo o aplico mientras la pasada llegaba a ella, y
 * no le ha pasado nada.
 */
enum ScheduledOffboardingOutcome: string
{
    case Applied = 'applied';
    case Deferred = 'deferred';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
