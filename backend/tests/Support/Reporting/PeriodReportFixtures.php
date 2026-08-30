<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Time\Instants;

/**
 * Jornadas y contratos para las pruebas del informe por periodo (tarea 2.8).
 *
 * ## Las jornadas se escriben con el agregado, no a mano
 *
 * {@see self::workDay()} usa `WorkDay` y su repositorio y **deja que el proyector
 * recalcule `daily_totals`**, igual que hace el fichaje de verdad. Escribir la
 * fila de la proyeccion a mano habria sido mas corto y habria roto la prueba: lo
 * que el informe afirma es que lee lo que la proyeccion escribio, asi que una
 * prueba que fabricara los dos extremos no comprobaria nada. Con esto, un cambio
 * en RN-06 rompe estas pruebas, que es lo que tiene que pasar.
 *
 * Las horas se pasan en **hora de reloj del centro**, que es como estan
 * enunciados los escenarios; la conversion a UTC la hace {@see Instants}.
 *
 * ## Los contratos si van a mano, y es otra decision
 *
 * {@see self::contract()} escribe con el constructor de consultas y no por el
 * caso de uso. No es pereza: `RegisterEmploymentContract` **cierra el contrato
 * anterior**, asi que sembrar una serie historica con el obligaria a insertarla
 * en orden cronologico y no permitiria escribir el caso que hace falta probar
 * —un contrato ya cerrado con su fecha exacta—. El caso de uso tiene sus propias
 * pruebas, donde lo que se ejercita es precisamente ese encadenamiento.
 */
final class PeriodReportFixtures
{
    /**
     * Una jornada ya registrada, con su proyeccion recalculada.
     *
     * `$clockOut` a `null` deja el turno **abierto**, que es el caso que decide
     * si un dia aporta minutos o no.
     */
    public static function workDay(
        int $siteId,
        string $employeeUuid,
        string $workDate,
        string $clockIn,
        ?string $clockOut,
        string $timeZone = 'Europe/Madrid',
    ): void {
        $repository = app(WorkDayRepository::class);
        $projector = app(DailyTotalsProjector::class);

        $day = WorkDay::start($employeeUuid, $siteId, WorkDate::fromIsoDate($workDate, new \DateTimeZone($timeZone)));
        $day->clockIn(Str::uuid7()->toString(), Instants::inMadrid($clockIn), ScanOrigin::QR_KIOSK);

        if ($clockOut !== null) {
            $day->clockOut(Instants::inMadrid($clockOut), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
        }

        $repository->save($day);

        foreach ($day->releaseEvents() as $event) {
            if ($event instanceof DailyTotalsRecalculated) {
                $projector->handle($event);
            }
        }
    }

    /**
     * Un contrato con su vigencia. `$validTo` a `null` es el contrato abierto.
     */
    public static function contract(
        string $employeeUuid,
        float $weeklyHours,
        string $validFrom,
        ?string $validTo = null,
        ScheduleType $scheduleType = ScheduleType::Shifts,
    ): void {
        $employeeId = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

        DB::table('employment_contracts')->insert([
            'employee_id' => $employeeId,
            'weekly_hours' => $weeklyHours,
            'annual_hours' => null,
            'schedule_type' => $scheduleType->value,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'created_at' => (string) now(),
            'created_by_user_id' => null,
        ]);
    }

    /**
     * Una fila de `daily_totals` escrita directamente.
     *
     * **Solo para la prueba de volumen de RNF-P-05**, y por eso vive aparte de
     * {@see self::workDay()}: medir el plan de la consulta con cuatrocientas mil
     * filas exige generarlas en segundos, y hacerlo por el agregado —un `INSERT`
     * en `shift_entries`, un evento y un recalculo por jornada— tardaria horas.
     * Lo que ahi se mide es el plan de PostgreSQL, no la coherencia de la
     * proyeccion, que es lo que comprueban las demas.
     */
    public static function projectedDay(
        int $employeeId,
        string $workDate,
        int $totalMinutes,
        int $shiftCount = 1,
    ): void {
        DB::table('daily_totals')->insert([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'total_minutes' => $totalMinutes,
            'shift_count' => $shiftCount,
            'first_in_at' => null,
            'last_out_at' => null,
            'has_open_shift' => false,
            'has_incident' => false,
            'recalculated_at' => (string) now(),
        ]);
    }
}
