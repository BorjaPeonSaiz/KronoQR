<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Un descarte del servidor que no se pudo atribuir a nadie** (RN-22, ADR-061
 * A2): ni por la firma de la tarjeta, ni por la huella de su contenido, ni por
 * el codigo de empleado. La revision diaria lo convierte en una incidencia
 * `discarded_scan` **sin persona**, una por quiosco y jornada, para el
 * administrador, que cruza el quiosco y la hora con el cuadrante.
 *
 * **Sin datos personales** (regla dura 21): el quiosco por su UUID y el fichaje
 * por su `scan_id`. Solo el servidor descarta sin atribuir con incidencia: el
 * aviso de un quiosco sin dueño no abre nada (ADR-047).
 */
final readonly class UnattributedServerDiscard
{
    public function __construct(
        public string $scanId,
        /** `devices.uuid` del quiosco del fichaje. */
        public string $deviceUuid,
        public ScanOrigin $origin,
        /** Momento real del fichaje, segun la tablet, en UTC. */
        public DateTimeImmutable $occurredAt,
        /** Cuando lo descarto el servidor, en UTC. */
        public DateTimeImmutable $recordedAt,
        /** Intentos fallidos contados, al menos uno. */
        public int $attempts,
    ) {
        if ($scanId === '' || $deviceUuid === '') {
            throw new InvalidArgumentException('Un descarte sin persona necesita su scan_id y su quiosco.');
        }

        if ($attempts < 1) {
            throw new InvalidArgumentException('Un descarte del servidor lleva al menos un intento fallido.');
        }

        TimeRange::assertUtc('occurredAt', $occurredAt);
        TimeRange::assertUtc('recordedAt', $recordedAt);
    }
}
