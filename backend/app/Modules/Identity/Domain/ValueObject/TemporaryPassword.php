<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Una contrasena temporal recien generada: el valor en claro y su hash
 * (**RF-ID-10**).
 *
 * **El valor en claro existe solo aqui y solo durante la peticion que la
 * emite.** Se guarda el hash, se devuelve una vez en la respuesta y se olvida:
 * no entra en ningun evento, en ningun asiento ni en ningun log (reglas duras 6
 * y 21).
 *
 * **Y se defiende de los tres sitios por donde suele escaparse un secreto**:
 * un volcado de depuracion (`__debugInfo`), una serializacion —una cola, una
 * cache, una sesion— (`__serialize` falla) y una traza de excepcion
 * (`#[SensitiveParameter]` en el constructor; la otra defensa es
 * `zend.exception_ignore_args=On` en el `php.ini` del producto).
 */
final readonly class TemporaryPassword
{
    public function __construct(
        #[SensitiveParameter] public string $plain,
        #[SensitiveParameter] public string $hash,
    ) {
        if ($plain === '' || $hash === '') {
            throw new InvalidArgumentException('Una contrasena temporal necesita su valor y su hash.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['plain' => '***', 'hash' => '***'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Una contrasena temporal no se serializa: existe solo en la respuesta que la emite.');
    }
}
