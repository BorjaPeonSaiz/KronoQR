<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Los cinco contadores agregados de `usage_7d` (**RF-PD-12**, ficha 5.10 punto 8).
 *
 * ## Cuatro son del PERIODO y uno es de AHORA
 *
 * Los cuatro primeros son **diferencias** entre el contador acumulado de hoy y
 * el que habia en el envio anterior; `incidentsOpen` es un recuento
 * instantaneo, porque «incidencias abiertas» es un nivel y no un flujo: restar
 * dos niveles no daria «las que se abrieron», daria «cuantas mas hay», que no
 * es lo que nadie querria leer.
 *
 * ## `null` significa «no se ha podido saber», nunca `0`
 *
 * Un `0` afirma que no hubo ni un fichaje en una semana, que es una noticia. Un
 * `null` dice que la serie no existia —Redis recien arrancado, un envio que es
 * el primero y no tiene con que comparar— y no afirma nada. La ficha lo pide
 * con esas palabras: *si una serie no existe, `null`, nunca inventar*.
 *
 * ## Un contador que va hacia atras da `null`
 *
 * Redis puede perder sus claves —es tambien la cache— y entonces el acumulado
 * de hoy es menor que el guardado. La diferencia seria negativa, y un «-84.000
 * escaneos» en un panel del fabricante es peor que un hueco.
 */
final readonly class TelemetryUsage
{
    public function __construct(
        public ?int $scansAccepted,
        public ?int $scansRejected,
        public ?int $batchesSynced,
        public ?int $incidentsOpen,
        public ?int $reportsGenerated,
    ) {}

    /** Ninguna serie disponible: el estado de un primer envio. */
    public static function unknown(): self
    {
        return new self(null, null, null, null, null);
    }

    /**
     * @return array{scans_accepted: int|null, scans_rejected: int|null, batches_synced: int|null, incidents_open: int|null, reports_generated: int|null}
     */
    public function toArray(): array
    {
        return [
            'scans_accepted' => $this->scansAccepted,
            'scans_rejected' => $this->scansRejected,
            'batches_synced' => $this->batchesSynced,
            'incidents_open' => $this->incidentsOpen,
            'reports_generated' => $this->reportsGenerated,
        ];
    }

    /**
     * La diferencia entre dos lecturas del mismo contador acumulado.
     *
     * @param  array<string, int>  $current
     * @param  array<string, int>  $baseline
     */
    public static function delta(array $current, array $baseline, string $key): ?int
    {
        $now = $current[$key] ?? null;
        $before = $baseline[$key] ?? null;

        if ($now === null || $before === null || $now < $before) {
            return null;
        }

        return $now - $before;
    }
}
