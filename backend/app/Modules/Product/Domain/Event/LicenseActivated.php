<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha activado una clave de licencia (RF-PD-04, RL-04, regla dura 6).
 *
 * ## Por que se audita
 *
 * Porque activar una clave cambia **que ha contratado el cliente** a ojos del
 * producto: el plan, los limites y las funcionalidades accesorias disponibles.
 * Es la afirmacion sobre la que se apoya cualquier conversacion comercial
 * posterior, y la unica forma de responder «¿desde cuando tiene este hotel el
 * plan grande?» o «¿quien metio esta clave y cuando?».
 *
 * `Compliance` lo sella como `license.activated`. Por un evento y no por una
 * llamada directa porque el §1.6 no concede la arista `Product -> Compliance`:
 * la misma via que la configuracion de instalacion (5.1) y el perfil de
 * cumplimiento (5.2).
 *
 * ## Lo que NO viaja
 *
 * **La clave firmada entera no viaja**, solo su huella corta. El asiento acaba
 * en el trail, el trail sale en la exportacion de auditoria y no hay ninguna
 * razon para difundir ahi una cadena de 400 caracteres que ademas lleva el
 * nombre del cliente repetido. Y no hay ningun dato de empleado: aqui no los
 * hay (regla dura 21).
 */
final readonly class LicenseActivated implements DomainEvent
{
    /**
     * @param  list<string>  $features  Accesorias habilitadas por el plan.
     */
    public function __construct(
        public string $licenseId,
        public string $fingerprint,
        public string $customerName,
        public string $plan,
        public int $maxEmployees,
        public int $maxDevices,
        public array $features,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validUntil,
        /**
         * Estado resultante: `valid`, `expiring_soon`, `expired` o
         * `not_yet_valid`.
         *
         * **Se puede activar una clave ya caducada y queda constancia de ello.**
         * Rechazarla obligaria a un cliente que renueva tarde a llamar por
         * telefono, y ademas dejaria sin registrar el hecho comercial de que la
         * clave existio.
         */
        public string $resultingState,
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.license_activated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
