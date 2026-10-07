<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Identity\Domain\ValueObject\TemporaryPasswordLifetime;

/**
 * Lo que la instalacion decide de sus contrasenas temporales (RF-ID-10, regla
 * dura 13), ya resuelto desde la configuracion por el proveedor del modulo: el
 * caso de uso no lee `config()`.
 */
final readonly class TemporaryPasswordSettings
{
    /**
     * @param  int  $minLength  `IDENTITY_PASSWORD_MIN_LENGTH`. La temporal mide el
     *                          mayor entre esto y 20.
     */
    public function __construct(
        public TemporaryPasswordLifetime $lifetime,
        public int $minLength,
    ) {}
}
