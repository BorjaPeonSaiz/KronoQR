<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * **¿Abre incidencia este aviso de fichaje descartado?** (RN-22, ADR-047, F6 del
 * dictamen del bloque 18).
 *
 * El aviso se guarda siempre; la incidencia `discarded_scan` solo se abre si su
 * `occurred_at` —que lo pone la tablet— cae en una **ventana creible**. Sin ella,
 * quien tuviera un token de quiosco robado y un codigo de empleado podria
 * sembrar una incidencia por cada fecha que eligiera: futura, anterior al alta
 * o de hace años (y una de 2099 no la purgaria nunca la retencion).
 *
 * Las tres condiciones, todas a la vez:
 *
 * 1. **No posterior a la recepcion**, mas el desfase de reloj que la
 *    instalacion admite (`ATTENDANCE_MAX_CLOCK_SKEW_MINUTES`): un fichaje no
 *    ocurre despues de que el servidor recibe su aviso.
 * 2. **No anterior a que pudiera existir**: la emision de la tarjeta que lo
 *    atribuyo, o el inicio del dia del alta (en la zona del centro) si se
 *    atribuyo por codigo.
 * 3. **No mas antiguo que la ventana de revision** de la instalacion
 *    (`attendance.discard_review_window_days`, 31 de serie): la cola de una
 *    tablet no guarda fichajes de hace meses.
 *
 * Pura: no conoce el reloj (regla dura 2). Los umbrales llegan resueltos (regla
 * dura 14): el desfase del ajuste operativo, la ventana de la configuracion.
 */
final readonly class DiscardedScanReviewPolicy
{
    public function __construct(
        private int $skewToleranceSeconds,
        private int $windowDays,
    ) {
        if ($skewToleranceSeconds < 0) {
            throw new InvalidArgumentException('La tolerancia de desfase no puede ser negativa.');
        }

        if ($windowDays < 1) {
            throw new InvalidArgumentException('La ventana de revision de los descartes es de al menos un dia.');
        }
    }

    public function opensIncident(DiscardedScan $scan, DateTimeZone $siteTimezone): bool
    {
        $latest = $scan->recordedAt->modify('+'.$this->skewToleranceSeconds.' seconds');
        $oldest = $scan->recordedAt->modify('-'.$this->windowDays.' days');

        return $scan->occurredAt <= $latest
            && $scan->occurredAt >= $oldest
            && $scan->occurredAt >= $this->validFrom($scan, $siteTimezone);
    }

    private function validFrom(DiscardedScan $scan, DateTimeZone $siteTimezone): DateTimeImmutable
    {
        if ($scan->attribution === DiscardedScanAttributionMethod::CREDENTIAL && $scan->credentialIssuedAt instanceof DateTimeImmutable) {
            return $scan->credentialIssuedAt;
        }

        // El primer instante del dia civil del alta, en la zona del centro. Es
        // un instante explicito, no el reloj.
        return (new DateTimeImmutable($scan->ownerHiredOn.' 00:00:00', $siteTimezone))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
