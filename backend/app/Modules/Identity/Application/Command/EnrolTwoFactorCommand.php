<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Alta del segundo factor de una cuenta de gestion (RS-06).
 *
 * El UUID y la clave del contador, **nada mas**: la etiqueta con la que la cuenta
 * aparecera en el autenticador la resuelve el caso de uso leyendo la cuenta, y no
 * la declara quien llama. Si viajara en la orden, un cliente podria pedir que su
 * entrada del autenticador se llamara como la de otra persona.
 *
 * `throttleKey` es **la misma** que la de {@see VerifyTwoFactorCommand} para esa
 * cuenta, y tiene que serlo: el alta comparte el bloqueo por intentos con la
 * verificacion y la confirmacion, asi que quien esta bloqueado por probar codigos
 * tampoco puede estrenar secreto. Con contadores separados, generar un secreto
 * nuevo seria la forma de dejar el bloqueo atras. La compone la capa HTTP, igual
 * que en el acceso con contrasena.
 */
final readonly class EnrolTwoFactorCommand
{
    public function __construct(
        public string $userUuid,
        public string $throttleKey,
    ) {}
}
