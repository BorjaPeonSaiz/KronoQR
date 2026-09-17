<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Time\Instants;

/**
 * Una jornada **con pausa declarada** y los escaneos que la produjeron
 * (RF-AT-12, ADR-024, tarea 3.5).
 *
 * ## Por que hace falta un fixture y no basta con el de siempre
 *
 * Porque `opened_by` y `closed_by` no salen de `shift_entries`: salen de
 * `scan_events.result` de los escaneos que abrieron y cerraron cada tramo. Una
 * jornada montada solo con el agregado —que es lo que hacen los fixtures de
 * `Reporting`— produce dos tramos indistinguibles de dos jornadas seguidas, que
 * es exactamente el caso que la tarea 3.5 viene a distinguir.
 *
 * Los tramos se escriben con el agregado y su repositorio, como en el resto de
 * `Reporting`: lo que se ejercita en esas pruebas es la CONSULTA. Los escaneos
 * se insertan por SQL porque aqui solo interesan tres columnas suyas, y fichar
 * de verdad obligaria a fabricar credenciales que no vienen al caso.
 *
 * Las horas se pasan en hora de reloj de Madrid, como los escenarios.
 */
final class BreakFixtures
{
    /**
     * Jornada de un turno partido por una pausa: entrada, pausa, vuelta y
     * salida, **todo en la misma jornada**.
     *
     * @return array{first: string, second: string} Los `uuid` publicos de los dos tramos.
     */
    public static function dayWithBreak(
        int $site,
        string $employee,
        string $workDate,
        string $clockIn,
        string $breakStart,
        string $breakEnd,
        ?string $clockOut,
    ): array {
        $repository = app(WorkDayRepository::class);
        $projector = app(DailyTotalsProjector::class);

        $workDay = WorkDay::start($employee, $site, WorkDate::fromIsoDate($workDate, Instants::madrid()));

        $first = $workDay->clockIn(Str::uuid7()->toString(), Instants::inMadrid($clockIn), ScanOrigin::QR_KIOSK);
        $workDay->clockOut(Instants::inMadrid($breakStart), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

        $second = $workDay->clockIn(Str::uuid7()->toString(), Instants::inMadrid($breakEnd), ScanOrigin::QR_KIOSK);

        if ($clockOut !== null) {
            $workDay->clockOut(Instants::inMadrid($clockOut), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
        }

        $repository->save($workDay);

        foreach ($workDay->releaseEvents() as $event) {
            if ($event instanceof DailyTotalsRecalculated) {
                $projector->handle($event);
            }
        }

        $device = AttendanceFixtures::device($site);

        self::scan($device['id'], $employee, $first->uuid(), $clockIn, 'auto', 'clock_in');
        self::scan($device['id'], $employee, $first->uuid(), $breakStart, 'break_start', 'break_start');
        // La vuelta llega como `auto`: nadie pulsa nada para volver (decision 5
        // de la ficha). Lo que se guarda en `result` es lo que el servidor
        // decidio, y es de ahi de donde sale `opened_by`.
        self::scan($device['id'], $employee, $second->uuid(), $breakEnd, 'auto', 'break_end');

        if ($clockOut !== null) {
            self::scan($device['id'], $employee, $second->uuid(), $clockOut, 'auto', 'clock_out');
        }

        return ['first' => $first->uuid(), 'second' => $second->uuid()];
    }

    /** Un escaneo aceptado apuntando a su tramo. */
    private static function scan(
        int $deviceId,
        string $employeeUuid,
        string $shiftEntryUuid,
        string $occurredAt,
        string $intent,
        string $result,
    ): void {
        DB::table('scan_events')->insert([
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $deviceId,
            'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
            'occurred_at' => Instants::inMadrid($occurredAt)->format('Y-m-d H:i:sP'),
            'recorded_at' => Instants::inMadrid($occurredAt)->format('Y-m-d H:i:sP'),
            'origin' => 'qr_kiosk',
            'intent' => $intent,
            'result' => $result,
            'shift_entry_id' => DB::table('shift_entries')->where('uuid', $shiftEntryUuid)->value('id'),
            'client_meta' => '{}',
            'clock_skew_seconds' => 0,
            'flagged_for_review' => false,
            'worked_minutes' => 0,
        ]);
    }
}
