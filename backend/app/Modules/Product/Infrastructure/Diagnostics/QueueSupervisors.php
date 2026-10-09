<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

/**
 * Si hay un supervisor de la cola en marcha (RF-PD-13; V3-PL-07 y V4-PL-3 de
 * la verificacion final de la 2.2.0).
 *
 * Mirar la cola no basta: con la cola vacia, «nadie consume» y «se consume al
 * ritmo que llega» son indistinguibles, y `queue.worker` decia «hay alguien
 * consumiendo» con Horizon parado. Lo que si se puede preguntar es si el
 * supervisor ha dado señales de vida en los ultimos segundos.
 *
 * Interfaz y no la clase de Horizon directamente para que `QueueProbe` se pueda
 * probar sin Redis.
 */
interface QueueSupervisors
{
    public const string RUNNING = 'running';

    public const string PAUSED = 'paused';

    /** Ningun supervisor ha dado señales de vida en la ventana de su latido. */
    public const string INACTIVE = 'inactive';

    /** No se ha podido preguntar (Redis caido, Horizon sin instalar). */
    public const string UNKNOWN = 'unknown';

    /**
     * @return array{status: string, masters: int|null}
     */
    public function status(): array;
}
