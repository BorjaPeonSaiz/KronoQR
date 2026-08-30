<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;

/**
 * Doble de `incident_resolution_seconds{type}` que se puede leer (doc 02 §8.2).
 *
 * El adaptador real escribe en Redis y **se traga sus propios fallos** —medir no
 * puede romper una resolucion ya confirmada—, asi que una prueba contra el no
 * distinguiria «observo» de «lo intento y no pudo». Con este doble, la
 * afirmacion es sobre lo que la aplicacion decidio observar y con que cifra.
 *
 * Mismo patron y misma forma que `RecordingCorrectionMetrics`: acumula por
 * etiqueta, que es como lo hace Prometheus.
 */
final class RecordingIncidentResolutionMetrics implements IncidentResolutionMetrics
{
    /** @var list<array{type: string, seconds: int}> */
    private array $observations = [];

    public function resolutionObserved(IncidentType $type, int $seconds): void
    {
        $this->observations[] = ['type' => $type->value, 'seconds' => $seconds];
    }

    /**
     * Lo observado, en orden. Vacio significa que no se observo nada, que es lo
     * que tiene que ocurrir cuando la resolucion no llego a escribirse.
     *
     * @return list<array{type: string, seconds: int}>
     */
    public function observations(): array
    {
        return $this->observations;
    }
}
