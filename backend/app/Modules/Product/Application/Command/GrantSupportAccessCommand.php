<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

use App\Modules\Product\Domain\ValueObject\SupportScope;

/**
 * La orden de conceder un acceso temporal de soporte (**RF-PD-11**).
 *
 * **El autor no se declara, se toma de la sesion.** En la API lo pone el
 * `FormRequest` desde el token; en la consola lo pone el comando desde la cuenta
 * que se le indique. Aceptarlo en el cuerpo permitiria firmar la autorizacion de
 * un acceso del fabricante a nombre de otra persona, que es la falsificacion mas
 * util que se le puede hacer a este trail.
 */
final readonly class GrantSupportAccessCommand
{
    public function __construct(
        public string $reason,
        public SupportScope $scope,
        public int $hours,
        /** `users.id` de quien lo autoriza. Nunca nulo: ver el docblock. */
        public int $grantedByUserId,
    ) {}
}
