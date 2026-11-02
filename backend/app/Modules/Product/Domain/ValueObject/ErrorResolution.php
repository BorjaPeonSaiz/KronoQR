<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Una resolucion pasada de un grupo de errores (RF-PD-15, 2.3.0): cuando se dio
 * por atendido y quien. Es una fila de `error_event_resolutions`, la tabla
 * solo-append que reabrir el grupo no vacia.
 *
 * El autor es una cuenta de **gestion**, como en {@see ErrorEvent}: `uuid` y
 * nombre, los dos nulos si la cuenta se borro despues (`nullOnDelete`). Igual
 * que alli, **no sale en el paquete de diagnostico** ni hacia un acceso de
 * soporte: el enmascarado es de la capa HTTP.
 */
final readonly class ErrorResolution
{
    public function __construct(
        public DateTimeImmutable $resolvedAt,
        public ?string $resolvedByUuid,
        public ?string $resolvedByName,
    ) {}
}
