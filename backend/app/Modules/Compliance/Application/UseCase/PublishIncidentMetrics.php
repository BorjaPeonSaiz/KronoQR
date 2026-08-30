<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\Port\IncidentMetrics;
use App\Modules\Compliance\Application\Port\IncidentTally;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Publica `incidents_open{type,severity}` (doc 02 §8.2, doc 01 §9.2).
 *
 * **Se recalcula entera desde la base de datos, nunca se incrementa** (regla
 * dura 7 aplicada a la instrumentacion). Es la diferencia entre un gauge que
 * baja cuando alguien resuelve una incidencia desde la bandeja (tarea 2.5) y un
 * contador que solo sube y que, a la semana, no describe nada.
 *
 * Por eso es una tarea programada y no un efecto de la deteccion: asi la cifra
 * recoge tambien lo que no pasa por el detector —una resolucion, una incidencia
 * abierta a mano— y un mensaje perdido no la desvia para siempre.
 */
final readonly class PublishIncidentMetrics
{
    public function __construct(
        private IncidentLedger $ledger,
        private IncidentMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * Devuelve cuantas incidencias abiertas hay en total, para el resumen del
     * comando.
     */
    public function handle(): int
    {
        $tallies = $this->ledger->openTally();

        $this->metrics->publish($tallies, $this->clock->now());

        return array_sum(array_map(
            static fn (IncidentTally $tally): int => $tally->open,
            $tallies,
        ));
    }
}
