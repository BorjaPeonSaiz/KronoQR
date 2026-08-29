<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * El centro de trabajo de la instalacion, visto desde fuera de `Workforce`.
 *
 * Hay exactamente uno (ADR-040): una licencia es un hotel. Lo devuelve el puerto
 * `Shared\Application\Port\InstallationSiteProvider`, que implementa `Workforce`
 * —que es donde esta `sites`— y consumen los satelites que necesitan nombrar o
 * etiquetar el centro sin poder importar nada de `Workforce` (ADR-025).
 *
 * `timezone` es un identificador IANA ya validado por quien lo persistio:
 * aqui solo se transporta.
 */
final readonly class InstallationSite
{
    public function __construct(
        public int $id,
        public string $name,
        public string $timezone,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('El identificador del centro es positivo.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('El centro tiene nombre.');
        }

        if (trim($timezone) === '') {
            throw new InvalidArgumentException('El centro tiene zona horaria.');
        }
    }
}
