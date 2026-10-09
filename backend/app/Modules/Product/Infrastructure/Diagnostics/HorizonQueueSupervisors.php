<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * El latido de los supervisores de Horizon, leido de Redis (RF-PD-13).
 *
 * Es lo mismo que mira `php artisan horizon:status`: cada supervisor maestro se
 * anota en Redis cada pocos segundos y Horizon solo devuelve los que lo han
 * hecho en los ultimos 14. Con el contenedor `horizon` parado la lista sale
 * vacia aunque la cola tambien lo este, que es justo el caso en que mirar la
 * cola no dice nada.
 *
 * Nunca lanza: sin Redis la respuesta es «no se sabe», y `queue.redis` ya dice
 * por que.
 */
final readonly class HorizonQueueSupervisors implements QueueSupervisors
{
    public function __construct(private MasterSupervisorRepository $masters) {}

    public function status(): array
    {
        try {
            $masters = $this->masters->all();
        } catch (Throwable) {
            return ['status' => self::UNKNOWN, 'masters' => null];
        }

        if ($masters === []) {
            return ['status' => self::INACTIVE, 'masters' => 0];
        }

        foreach ($masters as $master) {
            if (\is_object($master) && property_exists($master, 'status') && $master->status === 'paused') {
                return ['status' => self::PAUSED, 'masters' => \count($masters)];
            }
        }

        return ['status' => self::RUNNING, 'masters' => \count($masters)];
    }
}
