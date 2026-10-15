<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Port\WorkRecordExtent;

/**
 * Lo que la correccion de la zona hizo o haria: la zona anterior, la
 * nueva y cuanto registro horario hay (RN-23, ADR-056 §3). Es lo que la consola
 * ensena antes de pedir confirmacion y despues de corregir.
 *
 * En la vista previa, `outcome` dice si se puede seguir: solo con `Corrected`
 * hay algo que confirmar. `currentTimezone` es la zona que el centro tenia al
 * leerlo —`null` sin centro— y `extent` es `null` sin registro horario.
 */
final readonly class SiteTimezoneCorrectionReport
{
    public function __construct(
        public SiteTimezoneCorrectionOutcome $outcome,
        public ?string $currentTimezone,
        public string $newTimezone,
        public ?WorkRecordExtent $extent,
    ) {}
}
