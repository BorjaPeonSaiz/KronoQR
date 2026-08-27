<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\Model\Credential;

/**
 * Una credencial tal y como se enseña fuera de este modulo: el agregado mas el
 * **UUID publico** de su titular.
 *
 * Existe porque `Credential` guarda la clave interna del empleado —es la que
 * declara la clave ajena de la tabla— y esa clave no sale nunca de la base de
 * datos. La traduccion la hace el caso de uso, una vez, en lugar de repetirla en
 * cada `Resource` y en cada comando.
 *
 * **No lleva el payload.** El payload solo existe en la emision, y para eso esta
 * {@see IssuedCredential}. Que sean dos objetos distintos es lo que hace
 * imposible devolver por descuido el QR de una credencial ya emitida.
 */
final readonly class CredentialView
{
    public function __construct(
        public Credential $credential,
        public string $employeeUuid,
    ) {}
}
