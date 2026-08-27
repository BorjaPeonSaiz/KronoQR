<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\InvalidCorrection;
use DateTimeImmutable;

/**
 * Quien rectifico, cuando y por que (RN-13, RL-04, regla dura 5).
 *
 * RN-13 no dice «las correcciones se guardan»: dice que conservan la version
 * anterior **con su autor, momento y motivo**. Los tres juntos, o la correccion
 * no sirve para lo unico para lo que existe, que es explicar ante una
 * inspeccion por que las horas de una persona no son las que se ficharon. Por
 * eso son un objeto y no tres parametros: no hay ningun camino en el que se
 * pueda escribir una correccion a la que le falte uno.
 *
 * **El autor es una persona, nunca «el sistema».** `performedByUserId` es
 * obligatorio y es la clave de `users`, igual que `credentials.
 * delivered_by_user_id` en RF-QR-06: una rectificacion del registro horario la
 * firma alguien con nombre. Un proceso automatico no corrige fichajes; abre
 * incidencias (RN-08, regla dura 19).
 *
 * **El momento llega resuelto, del puerto `Clock`** (regla dura 2, ADR-021). El
 * dominio no pregunta la hora ni aqui ni en ningun sitio, y es lo que permite
 * probar una correccion hecha durante el cambio de hora sin esperar a octubre.
 *
 * Ojo con las dos fechas que conviven en una correccion y no son la misma: el
 * `performedAt` de este objeto es **cuando se corrigio**, y las marcas del tramo
 * son **cuando se trabajo**. La segunda puede ser de hace tres semanas.
 */
final readonly class Correction
{
    private function __construct(
        /** `users.id` de quien firma la rectificacion (`shift_corrections.performed_by_user_id`). */
        public int $performedByUserId,
        /** Momento de la rectificacion, en UTC, servido por `Clock` (`shift_corrections.created_at`). */
        public DateTimeImmutable $performedAt,
        public CorrectionReason $reason,
    ) {
        if ($performedByUserId < 1) {
            throw InvalidCorrection::withoutAuthor();
        }

        TimeRange::assertUtc('performedAt', $performedAt);
    }

    public static function by(int $performedByUserId, DateTimeImmutable $performedAt, CorrectionReason $reason): self
    {
        return new self($performedByUserId, $performedAt, $reason);
    }
}
