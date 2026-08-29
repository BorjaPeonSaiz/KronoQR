<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Alta del segundo factor de una cuenta de gestion (RS-06).
 *
 * Solo el UUID: la etiqueta con la que la cuenta aparecera en el autenticador la
 * resuelve el caso de uso leyendo la cuenta, y no la declara quien llama. Si
 * viajara en la orden, un cliente podria pedir que su entrada del autenticador se
 * llamara como la de otra persona.
 */
final readonly class EnrolTwoFactorCommand
{
    public function __construct(public string $userUuid) {}
}
