<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

/**
 * La orden de retirar un acceso de soporte (**RF-PD-11**).
 *
 * `revokedByUserId` es nulo desde la consola —ahi no hay sesion que atribuir— y
 * eso es informacion, no un hueco: distingue «la retiro Marta desde el panel» de
 * «la retiro quien tiene acceso al servidor».
 */
final readonly class RevokeSupportAccessCommand
{
    public function __construct(
        public string $grantUuid,
        public ?int $revokedByUserId,
    ) {}
}
