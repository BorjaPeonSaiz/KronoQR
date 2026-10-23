<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Lo que el servidor sabe de los intentos fallidos de **un** fichaje (RN-22,
 * ADR-061): cuantos lleva y cuando fallo el primero.
 *
 * Solo cuentan los intentos que llegaron **del mismo quiosco y con el mismo
 * contenido** que el primero, y solo los que fallaron por una causa que no es
 * una dependencia caida ni una contencion transitoria (ADR-059 §1). Esa
 * seleccion la hace el puerto que lleva la cuenta, no este objeto; aqui llega
 * el resultado.
 *
 * `attempts` **incluye el intento que se esta decidiendo**: la historia se lee
 * despues de apuntar el fallo actual, y por eso nunca es cero.
 *
 * **Sin datos personales** (regla dura 21) y **sin reloj** (regla dura 2): el
 * instante llega resuelto, en UTC, del reloj del servidor —nunca del de la
 * tablet, que puede estar desfasado (RF-AT-10)—.
 *
 * @see UnprocessableScanPolicy
 */
final readonly class ScanFailureHistory
{
    public function __construct(
        /** Intentos fallidos contados, el actual incluido. */
        public int $attempts,
        /** Reloj del servidor en el primer intento contado, en UTC. */
        public DateTimeImmutable $firstFailedAt,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException(
                'La historia de fallos de un fichaje incluye el intento actual: al menos uno, y han llegado '.$attempts.'.'
            );
        }

        TimeRange::assertUtc('firstFailedAt', $firstFailedAt);
    }
}
