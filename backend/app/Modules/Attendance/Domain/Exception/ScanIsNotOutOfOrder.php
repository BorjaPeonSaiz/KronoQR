<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Attendance\Domain\ValueObject\OutOfOrderScan;
use DateTimeImmutable;

/**
 * Un {@see OutOfOrderScan} que describe un escaneo que **si** encaja en la
 * jornada no es un dato raro: es un dato imposible.
 *
 * Se rechaza al construir el objeto de valor, igual que una duracion negativa
 * en {@see NegativeWorkedDuration}. La diferencia importa: quien recibe un
 * `OutOfOrderScan` sabe, por el tipo, que RN-18 se cumple, y no tiene que
 * volver a comparar los instantes para creerselo.
 *
 * Hay dos constructores con nombre porque hay dos formas de no encajar y **dos
 * limites opuestos** (RN-03 al cerrar, RN-02 al abrir); cada uno explica el
 * suyo, que es lo que hace util al mensaje cuando aparece en un log.
 *
 * **No la ve nunca el camino de fichaje.** Ahi el objeto lo construye el
 * agregado, que solo lo hace cuando la comparacion ya dio ese resultado; llegar
 * aqui significa que alguien lo fabrico a mano con los datos cambiados.
 */
final class ScanIsNotOutOfOrder extends AttendanceDomainException
{
    /** Camino de cierre: el escaneo es posterior a la entrada, asi que cierra el tramo sin problema. */
    public static function afterOpenEntry(DateTimeImmutable $occurredAt, DateTimeImmutable $openedAt): self
    {
        return new self(sprintf(
            'A scan that occurred at %s is not out of order for a shift entry opened at %s (RN-18).',
            $occurredAt->format(DateTimeImmutable::ATOM),
            $openedAt->format(DateTimeImmutable::ATOM),
        ));
    }

    /** Camino de apertura: el tramo cerrado ya habia terminado, asi que el nuevo no lo pisa. */
    public static function afterClosedEntry(DateTimeImmutable $occurredAt, DateTimeImmutable $endedAt): self
    {
        return new self(sprintf(
            'A scan that occurred at %s is not out of order for a shift entry that ended at %s (RN-18).',
            $occurredAt->format(DateTimeImmutable::ATOM),
            $endedAt->format(DateTimeImmutable::ATOM),
        ));
    }
}
