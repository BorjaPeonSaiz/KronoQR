<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * Una credencial pendiente de imprimir, con el perfil de su titular ya resuelto.
 *
 * Existe para que {@see MintCards} reciba las dos cosas emparejadas y no tenga
 * que volver a preguntar por el nombre de cada persona: en el lote de un centro
 * completo eso serian sesenta consultas dentro de la operacion mas lenta del
 * producto.
 */
final readonly class CardToMint
{
    public function __construct(
        public Credential $credential,
        public EmployeeCardProfile $holder,
    ) {}
}
