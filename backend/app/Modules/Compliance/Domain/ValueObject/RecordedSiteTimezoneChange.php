<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Una fila de `site_timezone_changes`: un cambio de zona hecho por la funcion
 * `site_correct_timezone` (RN-23, ADR-056; bloque 2 de la 2.3.0).
 *
 * La tabla solo la escribe esa funcion y la aplicacion no la puede tocar, asi
 * que cada fila es un cambio que ocurrio de verdad, lo hiciera la orden de
 * consola o no. `changedAt` lo pone la base de datos, no quien llama.
 */
final readonly class RecordedSiteTimezoneChange
{
    public function __construct(
        public string $from,
        public string $to,
        public DateTimeImmutable $changedAt,
    ) {}

    /** La clave con que se empareja con un asiento firmado. */
    public function key(): string
    {
        return $this->from.' -> '.$this->to;
    }
}
