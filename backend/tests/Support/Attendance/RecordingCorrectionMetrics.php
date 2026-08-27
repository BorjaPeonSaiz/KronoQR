<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Attendance\Application\Port\CorrectionMetrics;

/**
 * Doble de `manual_corrections_total{reason_code}` que se puede leer (doc 02
 * §8.2).
 *
 * El adaptador real escribe en Redis y **se traga sus propios fallos** —medir no
 * puede romper una correccion—, asi que una prueba contra el no distinguiria
 * «conto» de «lo intento y no pudo». Con este doble, la afirmacion es sobre lo
 * que la aplicacion decidio contar.
 *
 * Es el mismo patron que {@see RecordingScanMetrics}, y con la misma forma:
 * acumula por etiqueta, que es como lo hace Prometheus.
 */
final class RecordingCorrectionMetrics implements CorrectionMetrics
{
    /** @var array<string, int> */
    private array $counts = [];

    public function correctionRecorded(string $reasonCode): void
    {
        $this->counts[$reasonCode] = ($this->counts[$reasonCode] ?? 0) + 1;
    }

    /**
     * Lo contado, por motivo. Vacio significa que no se conto nada, que es lo
     * que tiene que ocurrir cuando la correccion no llego a aplicarse.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }
}
