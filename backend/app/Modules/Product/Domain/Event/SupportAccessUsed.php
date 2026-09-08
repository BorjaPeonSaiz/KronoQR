<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha usado un acceso de soporte: una peticion autenticada con su token
 * (**RF-PD-11**, ADR-020, regla dura 6).
 *
 * ## Se publica una vez por ventana, no una por peticion
 *
 * Lo decide el caso de uso con el puerto `SupportAccessRecorder`, y el motivo es
 * el mismo por el que ADR-037 agrupa las lecturas de datos personales: un
 * asiento por peticion convertiria una sesion de soporte de veinte minutos en
 * cientos de escrituras bajo el candado global de ADR-010 —el mismo por el que
 * pasa cada fichaje—. La ventana es `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS`
 * (900 de serie).
 *
 * **`accessed_at` si se actualiza en cada peticion**, porque no cuesta lo mismo:
 * es un `UPDATE` de una fila sin candado, y es lo que hace que el panel diga
 * «usado hace dos minutos» y no «usado hace un cuarto de hora».
 *
 * ## Lo que lleva, y por que la ruta
 *
 * El metodo y la ruta de la peticion que **abrio** la ventana. Es lo que
 * convierte el asiento en algo accionable: «entro y lo primero que hizo fue
 * pedir el paquete de diagnostico» frente a «entro y lo primero que hizo fue
 * listar la plantilla». La ruta es el patron registrado —`api/v1/employees/{uuid}`—
 * y no la URL concreta: un UUID de empleado en `audit_log.payload` seria un dato
 * personal escrito en un sitio que se exporta.
 */
final readonly class SupportAccessUsed implements DomainEvent
{
    public function __construct(
        public int $grantId,
        public string $grantUuid,
        public string $scope,
        /** `GET`, `POST`... de la peticion que abrio la ventana. */
        public string $method,
        /** El patron de la ruta, nunca la URL con sus identificadores. */
        public string $route,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.support_access_used';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
