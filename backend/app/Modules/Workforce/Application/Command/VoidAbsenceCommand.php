<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Lo que hace falta para anular una ausencia (**RF-GP-04**).
 *
 * Dos campos y ni uno mas: cual y por que. No hay nada que rectificar —eso es
 * corregir— y quien lo hace lo resuelve la sesion en curso.
 */
final readonly class VoidAbsenceCommand
{
    public function __construct(
        /** UUID de la version que se anula. Tiene que ser la **vigente**. */
        public string $absenceUuid,
        /** Por que se anula. Obligatorio, texto libre de 3 a 500. */
        public string $reason,
        public ?int $voidedByUserId = null,
    ) {}
}
