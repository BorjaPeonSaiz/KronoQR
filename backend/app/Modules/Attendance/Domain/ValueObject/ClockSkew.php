<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * La distancia entre **cuando ocurrio** un escaneo y **cuando lo recibio** el
 * servidor (`scan_events.clock_skew_seconds`, RF-AT-09, RN-15, RF-AT-10).
 *
 * ## Un solo numero para dos causas, y es deliberado
 *
 * El mismo valor lo produce un reloj de tablet desviado (RF-AT-10) y un escaneo
 * que espero en la cola offline (RN-15). El servidor **no puede distinguirlas**:
 * ve dos instantes y su diferencia. Fingir que si podria —dos columnas, dos
 * umbrales— seria inventar informacion que no existe. Lo que las dos causas
 * comparten es justo lo que importa aqui: el registro legal se escribe con la
 * hora del dispositivo (regla dura 9) y esa hora no la ha verificado nadie.
 *
 * ## El signo importa y por eso se conserva
 *
 * Positivo: el escaneo llego **despues** de ocurrir. Es el caso normal de la
 * cola offline y el de una tablet atrasada. Negativo: el `occurred_at` esta en
 * el **futuro** del servidor, que solo puede ser un reloj adelantado o un
 * cliente que miente. Se guardan los dos con su signo porque distinguirlos es
 * lo primero que necesita quien investigue la incidencia de la tarea 3.5.
 *
 * ## Lo que se compara es la magnitud
 *
 * {@see ReviewPolicy} mide sobre {@see magnitudeSeconds()}: retrasar una entrada
 * once horas y adelantarla once horas son el mismo problema para quien tiene
 * que validar el tramo. Un umbral con signo dejaria sin marcar exactamente la
 * mitad de los casos, y es la mitad que un cliente elegiria para retrodatar.
 *
 * **No conoce el reloj** (regla dura 2): recibe los dos instantes ya medidos.
 */
final readonly class ClockSkew
{
    private function __construct(
        /** `recorded_at` menos `occurred_at`, en segundos y con signo. */
        public int $seconds,
    ) {}

    public static function ofSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    /**
     * El desfase de un escaneo, medido entre sus dos marcas de tiempo
     * (RF-AT-09, regla dura 9).
     *
     * Los dos instantes tienen que estar en UTC (regla dura 3): comparar dos
     * marcas con desplazamientos distintos daria un desfase que solo describe
     * la zona horaria de quien lo calculo.
     */
    public static function between(DateTimeImmutable $occurredAt, DateTimeImmutable $recordedAt): self
    {
        TimeRange::assertUtc('occurredAt', $occurredAt);
        TimeRange::assertUtc('recordedAt', $recordedAt);

        return new self($recordedAt->getTimestamp() - $occurredAt->getTimestamp());
    }

    /** Cuanto se separan las dos marcas, sin importar en que sentido. */
    public function magnitudeSeconds(): int
    {
        return abs($this->seconds);
    }

    /** El `occurred_at` esta en el futuro del servidor: reloj adelantado. */
    public function isAhead(): bool
    {
        return $this->seconds < 0;
    }
}
