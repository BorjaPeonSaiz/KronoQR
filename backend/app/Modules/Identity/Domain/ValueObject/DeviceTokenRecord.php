<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

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
    ) {
        // La politica mide la vida del token restando estas dos marcas: un token
        // que caduca al nacer o antes no tiene 80 % que medir, y una marca con
        // desfase seria otro instante si alguien la formatea (regla dura 3,
        // R1-AR-04).
        if ($id <= 0) {
            throw new InvalidArgumentException('Un token de quiosco tiene un identificador positivo, y llego '.$id.'.');
        }

        if ($issuedAt->getOffset() !== 0 || ($expiresAt instanceof DateTimeImmutable && $expiresAt->getOffset() !== 0)) {
            throw new InvalidArgumentException('Las marcas de un token de quiosco van en UTC.');
        }

        if ($expiresAt instanceof DateTimeImmutable && $expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Un token de quiosco caduca despues de emitirse.');
        }
    }
}
