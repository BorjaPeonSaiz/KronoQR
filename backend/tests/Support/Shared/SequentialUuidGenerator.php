<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\UuidGenerator;

/**
 * Identificadores deterministas para las pruebas: `00000000-0000-7000-8000-`
 * seguido del numero de orden en hexadecimal, empezando por 1.
 *
 * Tienen la forma de un UUID v7 (version 7, variante RFC 4122), asi que pasan
 * cualquier validacion de formato, y se ordenan igual que se generaron, que es
 * la propiedad del v7 de la que dependen los indices. Es la segunda
 * implementacion del puerto que justifica que exista (doc 02 §3.5).
 */
final class SequentialUuidGenerator implements UuidGenerator
{
    private int $issued = 0;

    public function next(): string
    {
        $this->issued++;

        return \sprintf('00000000-0000-7000-8000-%012x', $this->issued);
    }

    public function issued(): int
    {
        return $this->issued;
    }
}
