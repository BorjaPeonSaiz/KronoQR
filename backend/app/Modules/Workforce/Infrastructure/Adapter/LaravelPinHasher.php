<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

/**
 * El hasher de la instalacion, detras del puerto (RF-ID-09).
 *
 * `Hash::make()` con el algoritmo y el coste de `config/hashing.php` — bcrypt
 * coste 12 en produccion, 4 en la suite. **El coste es del despliegue y no se
 * toca aqui**: encarecerlo es lo que hace caro un ataque por fuerza bruta contra
 * `pin_hash`, y abaratarlo por rendimiento seria pagar con seguridad una factura
 * que se paga mejor moviendo el calculo de sitio.
 *
 * Por el contrato de `Hasher` y no por la facade: asi la unica dependencia de
 * este adaptador es una interfaz del framework, sustituible en una prueba.
 */
final readonly class LaravelPinHasher implements PinHasher
{
    public function __construct(private Hasher $hasher) {}

    public function hash(#[SensitiveParameter] string $pin): PinMaterial
    {
        return new PinMaterial($pin, $this->hasher->make($pin));
    }
}
