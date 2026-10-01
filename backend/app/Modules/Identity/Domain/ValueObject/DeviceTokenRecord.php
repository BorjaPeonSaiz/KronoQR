<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Un token vivo de un quiosco, tal como lo ve la politica de rotacion
 * (RF-ID-04, ADR-044).
 *
 * **Sin el valor y sin su hash**: para decidir si toca rotar basta con saber
 * cual es, cuando nacio y cuando caduca. `id` es la clave del almacen de tokens,
 * opaca para el dominio; solo sirve para distinguir el token que firmo la
 * peticion de los demas del mismo dispositivo, y crece con cada emision, asi
 * que el mayor es el mas reciente.
 *
 * `expiresAt` es `null` para un token sin caducidad, que en este producto no
 * se emite nunca: la politica no lo rota, porque no se puede decir cuando esta
 * al 80 % de su vida.
 */
final readonly class DeviceTokenRecord
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
