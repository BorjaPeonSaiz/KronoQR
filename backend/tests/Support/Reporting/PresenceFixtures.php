<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;

/**
 * Turnos abiertos y cerrados escritos directamente en la tabla.
 *
 * Sin pasar por el caso de uso a proposito: lo que aqui se prueba es la
 * **consulta** de presencia, y montar cada escenario a base de escaneos ataria
 * estas pruebas al camino de fichaje —que ya tiene los suyos— y haria imposible
 * escribir estados que el dominio tarda horas en producir, como un turno abierto
 * de ayer.
 *
 * Datos ficticios: nada especifico de un cliente entra en el repositorio (regla
 * dura 13).
 */
final class PresenceFixtures
{
    /**
     * Un tramo abierto y, si se indica dispositivo, el `scan_events` que lo
     * abrio — que es de donde la consulta saca el quiosco de origen.
     *
     * @return string UUID publico del tramo.
     */
    public static function openShift(
        string $employeeUuid,
        int $siteId,
        string $clockedInAt = '2026-03-14 05:00:00+00',
        string $workDate = '2026-03-14',
        ?int $deviceId = null,
        string $source = 'qr_kiosk',
    ): string {
        $uuid = Str::uuid7()->toString();
        $employeeId = AttendanceFixtures::employeeIdOf($employeeUuid);

        $shiftEntryId = DB::table('shift_entries')->insertGetId([
            'uuid' => $uuid,
            'employee_id' => $employeeId,
            'site_id' => $siteId,
            'work_date' => $workDate,
            'clocked_in_at' => $clockedInAt,
            'clocked_out_at' => null,
            'duration_minutes' => null,
            'status' => 'open',
            'clock_in_source' => $source,
            'version' => 1,
        ]);

        if ($deviceId !== null) {
            DB::table('scan_events')->insert([
                'scan_id' => Str::uuid7()->toString(),
                'device_id' => $deviceId,
                'employee_id' => $employeeId,
                'occurred_at' => $clockedInAt,
                'recorded_at' => $clockedInAt,
                'origin' => $source,
                'intent' => 'auto',
                'result' => 'clock_in',
                'shift_entry_id' => $shiftEntryId,
                'worked_minutes' => 0,
            ]);
        }

        return $uuid;
    }

    /**
     * Un tramo ya cerrado. Existe para el control negativo: quien trabajo esta
     * mañana y ya se fue tiene que salir como **ausente**, no como presente.
     */
    public static function closedShift(
        string $employeeUuid,
        int $siteId,
        string $clockedInAt = '2026-03-14 05:00:00+00',
        string $clockedOutAt = '2026-03-14 09:00:00+00',
        string $workDate = '2026-03-14',
    ): void {
        DB::table('shift_entries')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
            'site_id' => $siteId,
            'work_date' => $workDate,
            'clocked_in_at' => $clockedInAt,
            'clocked_out_at' => $clockedOutAt,
            'duration_minutes' => 240,
            'status' => 'closed',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
        ]);
    }
}
