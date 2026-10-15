<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Corregir la zona horaria del centro con registro horario (RN-23, ADR-056 §3).
 *
 * Sin identificador de centro: es el de la instalacion (ADR-040).
 */
final readonly class CorrectSiteTimezoneCommand
{
    public function __construct(
        /** Identificador IANA de la zona correcta (`Atlantic/Canary`). */
        public string $timezone,
        /** De 10 a 1000 caracteres: que se configuro mal y como se supo. */
        public string $reason,
        /** `users.id` de la cuenta de gestion activa que responde de la correccion. */
        public int $responsibleUserId,
    ) {}
}
