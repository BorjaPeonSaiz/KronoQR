<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use DateTimeImmutable;

/**
 * La orden de abrir una incidencia a partir de un hallazgo de la deteccion
 * (RF-PR-01).
 *
 * **Habla en escalares y en identificadores publicos**, no en objetos de
 * `Attendance`: este modulo no puede importar aquel dominio (doc 02 §1.6), y el
 * listener que traduce el evento es justo la frontera donde eso se convierte.
 */
final readonly class OpenIncidentCommand
{
    /**
     * @param  string  $type  Valor de `incidents.type`, tal y como lo emitio la deteccion.
     * @param  array<string, int>  $context  Minutos medidos y umbral aplicado. Sin datos personales.
     */
    public function __construct(
        public string $type,
        public string $employeeUuid,
        public int $siteId,
        public string $workDate,
        public ?string $shiftEntryUuid,
        public DateTimeImmutable $detectedAt,
        public array $context = [],
    ) {}
}
