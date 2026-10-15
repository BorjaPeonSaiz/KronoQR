<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * Cuanto registro horario existe: tramos en cualquier estado y la primera y la
 * ultima jornada (`work_date`, `YYYY-MM-DD`). Es lo que la correccion de la zona
 * por consola ensena antes de pedir confirmacion (RN-23, ADR-056 §3).
 */
final readonly class WorkRecordExtent
{
    public function __construct(
        public int $shiftEntryCount,
        public string $firstWorkDate,
        public string $lastWorkDate,
    ) {}
}
