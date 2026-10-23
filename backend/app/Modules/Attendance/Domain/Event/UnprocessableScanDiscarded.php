<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * **El servidor ha descartado un fichaje que no conseguia procesar** (RN-22,
 * ADR-061, `UnprocessableScanVerdict::Discard`).
 *
 * Es la primera vez que el servidor decide por si solo que un fichaje que el
 * empleado vio confirmado en la pantalla del quiosco **no entra en el
 * registro**, y por eso deja asiento en `audit_log` (`scan.discarded`, actor
 * `system`, regla dura 6). El volumen lo acota el cupo por quiosco.
 *
 * **Sin datos personales ni texto libre** (reglas duras 6 y 21): el quiosco por
 * su UUID, el fichaje por su `scan_id`, la persona por su UUID y solo si se
 * atribuyo. Ni la clase de la excepcion —que es diagnostico y va al log— ni su
 * mensaje.
 */
final readonly class UnprocessableScanDiscarded implements DomainEvent
{
    public function __construct(
        /** UUID v7 generado en el cliente (regla dura 8). */
        public string $scanId,
        /** `devices.id` del quiosco, para el sujeto del asiento. */
        public int $deviceId,
        public string $deviceUuid,
        /** `qr_kiosk` o `pin_kiosk`. */
        public string $origin,
        /** Momento real del fichaje, segun la tablet (regla dura 9). */
        public DateTimeImmutable $scannedAt,
        /** Cuando lo descarto el servidor, con su reloj. */
        public DateTimeImmutable $discardedAt,
        /** Reloj del servidor en el primer intento contado. */
        public DateTimeImmutable $firstFailedAt,
        /** Intentos fallidos contados, el ultimo incluido. */
        public int $attempts,
        /** `credential`, `employee_code` o `none`. */
        public string $attribution,
        /** Titular atribuido; nulo sin atribucion. */
        public ?string $employeeUuid,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.unprocessable_scan_discarded';
    }

    /** El hecho es el descarte: ocurre cuando el servidor lo decide. */
    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->discardedAt;
    }
}
