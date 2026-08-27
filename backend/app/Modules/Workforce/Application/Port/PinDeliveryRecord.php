<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use DateTimeImmutable;

/**
 * Lo que queda escrito cuando el PIN se entrega en mano (RF-ID-09, RF-QR-06 para
 * la tarjeta).
 *
 * **Sin el PIN, y no por olvido.** El acuse responde a «quien entrego, a quien y
 * cuando», que es lo que hace falta el dia que alguien discuta un fichaje. Que
 * se entrego ya lo sabe quien pregunta.
 *
 * `deliveredByUserUuid` es el identificador **publico** de la cuenta de gestion:
 * la clave interna no sale de la base de datos y el nombre de quien entrego no
 * es un dato que la API tenga que repartir.
 */
final readonly class PinDeliveryRecord
{
    public function __construct(
        public string $employeeUuid,
        public DateTimeImmutable $deliveredAt,
        public string $deliveredByUserUuid,
    ) {}
}
