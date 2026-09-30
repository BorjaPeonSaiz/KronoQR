<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\InvalidFutureTolerance;
use App\Modules\Attendance\Domain\Exception\ShiftMarkInFuture;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateInterval;
use DateTimeImmutable;

/**
 * **Hasta donde puede llegar un tramo escrito a mano** (F1, RL-01, RL-04).
 *
 * Un registro horario anota lo que ya ha ocurrido. El alta manual y la
 * correccion del panel aceptaban cualquier `work_date`, `clocked_in_at` o
 * `clocked_out_at`, y con eso se podia rellenar la jornada teorica por
 * adelantado o cerrar un turno con la salida «prevista». Esta politica fija el
 * limite: **la hora del servidor mas un margen** configurable.
 *
 * ## El margen es operativo, no legal
 *
 * Existe porque el formulario redondea al minuto —quien cierra un turno «ahora»
 * a las 10:04:30 envia 10:05— y porque el reloj del navegador puede ir algo
 * adelantado. No lo fija ninguna norma, asi que no vive en el perfil de
 * cumplimiento (regla dura 14 habla de umbrales legales) sino en
 * `installation_settings` (`ATTENDANCE_FUTURE_TOLERANCE_MINUTES`), y llega aqui
 * ya resuelto: esta clase no consulta la configuracion.
 *
 * ## Solo el panel, nunca el quiosco
 *
 * El fichaje no pasa por aqui y no debe: la tablet nunca bloquea al empleado
 * por la hora (regla dura 19). Un `occurred_at` adelantado se registra y se
 * marca para revision con `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` (RF-AT-10).
 *
 * **No conoce el reloj** (regla dura 2): recibe el instante del servidor que el
 * caso de uso ya pidio al puerto `Clock`.
 */
final readonly class ManualEntryHorizon
{
    private function __construct(
        /** El ultimo instante admisible: la hora del servidor mas el margen. */
        public DateTimeImmutable $latestAcceptable,
        public int $toleranceMinutes,
    ) {}

    /**
     * @throws InvalidFutureTolerance si el margen es negativo
     */
    public static function at(DateTimeImmutable $now, int $toleranceMinutes): self
    {
        TimeRange::assertUtc('now', $now);

        if ($toleranceMinutes < 0) {
            throw InvalidFutureTolerance::ofMinutes($toleranceMinutes);
        }

        return new self($now->add(new DateInterval('PT'.$toleranceMinutes.'M')), $toleranceMinutes);
    }

    /**
     * La jornada declarada no puede ser un dia civil posterior al del ultimo
     * instante admisible, **en la zona del centro** (RN-04): a las 23:58 del
     * dia 14 con cinco minutos de margen, la jornada del 15 ya se admite.
     *
     * @throws ShiftMarkInFuture
     */
    public function assertWorkDateAllowed(WorkDate $workDate): void
    {
        $latestDate = WorkDate::fromInstant($this->latestAcceptable, $workDate->timezone)->isoDate;

        // `Y-m-d` ordena igual como cadena que como fecha.
        if ($workDate->isoDate > $latestDate) {
            throw ShiftMarkInFuture::workDate($workDate->isoDate, $latestDate);
        }
    }

    /**
     * La entrada y, si la hay, la salida no pueden quedar por delante del
     * ultimo instante admisible. Igual se admite: el limite es inclusivo.
     *
     * @throws ShiftMarkInFuture
     */
    public function assertTimesAllowed(ShiftTimes $times): void
    {
        $this->assertInstant(FutureMark::ClockIn, $times->clockedInAt);

        if ($times->clockedOutAt instanceof DateTimeImmutable) {
            $this->assertInstant(FutureMark::ClockOut, $times->clockedOutAt);
        }
    }

    private function assertInstant(FutureMark $mark, DateTimeImmutable $instant): void
    {
        if ($instant > $this->latestAcceptable) {
            throw ShiftMarkInFuture::instant($mark, $instant, $this->latestAcceptable, $this->toleranceMinutes);
        }
    }
}
