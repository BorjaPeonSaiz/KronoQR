<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 90 dias de tramos con **volumen realista**: del orden de doscientas mil filas
 * entre `shift_entries`, `scan_events` y `daily_totals`.
 *
 * **Por que el volumen es parte de la tarea y no un capricho.** Una migracion
 * medida sobre diez filas no esta medida: el tiempo de `CREATE INDEX`, el coste
 * de la restriccion de exclusion de RN-02 y el plan de ejecucion de «quien esta
 * dentro ahora» solo se distinguen del ruido cuando hay datos. La skill
 * `/migracion-segura` lo dice sin rodeos: *«no 10 filas: cientos de miles»*.
 *
 * **Lo que este seeder NO hace, a proposito.** Nada de turnos nocturnos, ni
 * cambios de hora, ni olvidos de salida, ni correcciones: esos son los casos
 * limite del §10.2 y los anaden la tarea 1.4 (`EdgeCaseSeeder`) y la 1.15. Aqui
 * los datos son deliberadamente aburridos —jornadas normales, sin solapes— para
 * que cualquier fallo que aparezca al medir sea del esquema y no del dato.
 *
 * **Las tres formas de jornada** que se generan cubren lo que el sector tiene:
 * turno de manana, turno de tarde y **jornada partida** de dos tramos, que es la
 * que rompe la suposicion de «un empleado, una entrada y una salida al dia».
 *
 * `daily_totals` se construye al final con un `INSERT ... SELECT` que **agrega
 * los tramos vigentes**, no acumulando conforme se insertan (RN-06, regla dura
 * 7). Es la misma forma que tendra el recalculo del caso de uso: la proyeccion
 * se reconstruye, nunca se incrementa.
 */
final class VolumeSeeder extends Seeder
{
    /** Ventana del §11 del doc 02 para la semilla de desarrollo. */
    private const int DAYS = 90;

    /** Empleados por lote: acota la memoria y el tamano de cada `INSERT`. */
    private const int EMPLOYEES_PER_BATCH = 25;

    public function run(): void
    {
        if (DB::table('shift_entries')->exists()) {
            $this->command->warn(
                'VolumeSeeder: ya hay tramos en la base de datos y no se toca nada. '
                .'Para regenerarlos: php artisan migrate:fresh --seed',
            );

            return;
        }

        $generated = 0;

        foreach ($this->sites() as $site) {
            $timezone = new DateTimeZone($site->timezone);
            $devices = $this->devicesOf($site->id);

            if ($devices === []) {
                continue;
            }

            $employees = $this->activeEmployeesOf($site->id);
            $workDates = $this->workDates($timezone);

            foreach (array_chunk($employees, self::EMPLOYEES_PER_BATCH) as $batch) {
                $generated += $this->seedBatch($batch, $site->id, $timezone, $devices, $workDates);
            }
        }

        $this->command->info('VolumeSeeder: '.$generated.' tramos generados en '.self::DAYS.' dias.');

        $this->projectDailyTotals();
    }

    /**
     * @return list<object{id: int, timezone: string}>
     */
    private function sites(): array
    {
        /** @var list<object{id: int, timezone: string}> $sites */
        $sites = DB::table('sites')->select(['id', 'timezone'])->orderBy('id')->get()->all();

        return $sites;
    }

    /**
     * @return list<int>
     */
    private function devicesOf(int $siteId): array
    {
        /** @var list<int> $devices */
        $devices = DB::table('devices')->where('site_id', $siteId)->orderBy('id')->pluck('id')->all();

        return $devices;
    }

    /**
     * Solo los activos: RN-14 dice que el empleado de baja no ficha, asi que
     * generarle jornadas de los ultimos 90 dias seria un dato imposible.
     *
     * @return list<int>
     */
    private function activeEmployeesOf(int $siteId): array
    {
        /** @var list<int> $employees */
        $employees = DB::table('employees')
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $employees;
    }

    /**
     * Genera, inserta y encadena los escaneos de un lote de empleados.
     *
     * Se inserta primero el tramo y despues sus dos escaneos porque
     * `scan_events.shift_entry_id` apunta al tramo. Los identificadores se
     * recuperan por `employee_id` en una sola consulta por lote: la alternativa
     * —`insertGetId` fila a fila— multiplicaria por 50.000 el numero de idas y
     * vueltas a la base de datos.
     *
     * @param  list<int>  $employeeIds
     * @param  list<int>  $deviceIds
     * @param  list<string>  $workDates
     */
    private function seedBatch(array $employeeIds, int $siteId, DateTimeZone $timezone, array $deviceIds, array $workDates): int
    {
        $shifts = [];

        foreach ($employeeIds as $ordinal => $employeeId) {
            foreach ($this->shiftsOf($employeeId, $ordinal, $workDates, $timezone) as $shift) {
                $shifts[] = $shift + ['site_id' => $siteId];
            }
        }

        if ($shifts === []) {
            return 0;
        }

        foreach (array_chunk($shifts, 2_000) as $chunk) {
            DB::table('shift_entries')->insert($chunk);
        }

        $this->seedScansFor($employeeIds, $deviceIds);

        return \count($shifts);
    }

    /**
     * Las jornadas de un empleado en la ventana.
     *
     * Cinco dias de trabajo y dos de descanso, desplazados por empleado para que
     * no libre toda la plantilla el mismo dia. El patron —manana, tarde o
     * partida— tambien depende del empleado, no del dia: quien hace turno de
     * tarde lo hace toda la semana.
     *
     * @param  list<string>  $workDates
     * @return list<array<string, string|int|null>>
     */
    private function shiftsOf(int $employeeId, int $ordinal, array $workDates, DateTimeZone $timezone): array
    {
        $pattern = ($employeeId + $ordinal) % 3;
        $restOffset = $employeeId % 7;
        $segments = $this->segmentsOf($pattern);
        $shifts = [];

        foreach ($workDates as $day => $workDate) {
            if (($day + $restOffset) % 7 < 2) {
                continue;  // Descanso semanal.
            }

            foreach ($segments as $segment) {
                $shifts[] = $this->shiftRow($employeeId, $workDate, $segment[0], $segment[1], $timezone);
            }
        }

        return $shifts;
    }

    /**
     * Hora local de inicio y minutos de duracion de cada tramo del patron.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function segmentsOf(int $pattern): array
    {
        return match ($pattern) {
            0 => [['07:00', 8 * 60]],
            1 => [['15:00', 8 * 60]],
            // Jornada partida: dos tramos el mismo dia, con cuatro horas de
            // corte entre ellos. Es el caso que rompe «una entrada y una salida
            // al dia» y el que hace que RN-06 tenga que sumar, no sustituir.
            default => [['09:00', 4 * 60], ['17:00', 4 * 60]],
        };
    }

    /**
     * Las 90 fechas civiles de la ventana **en la zona del centro** (RN-05),
     * de la mas antigua a la mas reciente y terminando ayer.
     *
     * La jornada es una fecha civil del centro, no del servidor: un hotel en
     * Atlantic/Canary y otro en Europe/Madrid no comparten el dia natural
     * aunque compartan instalacion.
     *
     * @return list<string>
     */
    private function workDates(DateTimeZone $timezone): array
    {
        $today = now()->setTimezone($timezone->getName());
        $dates = [];

        for ($day = self::DAYS; $day >= 1; $day--) {
            $dates[] = $today->copy()->subDays($day)->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function shiftRow(int $employeeId, string $workDate, string $startsAt, int $minutes, DateTimeZone $timezone): array
    {
        // Se construye la hora LOCAL del centro y se convierte a UTC (regla
        // dura 3). Al reves —restar horas a un instante UTC— el turno se
        // desplazaria una hora en cada cambio de hora.
        $localStart = new DateTimeImmutable($workDate.' '.$startsAt, $timezone);
        $start = $localStart->setTimezone(new DateTimeZone('UTC'));
        $end = $start->modify('+'.$minutes.' minutes');

        return [
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'clocked_in_at' => $start->format('Y-m-d H:i:sP'),
            'clocked_out_at' => $end->format('Y-m-d H:i:sP'),
            // La duracion se guarda como la diferencia real entre los dos
            // instantes UTC, que es lo que RN-09 exige: el dia de un cambio de
            // hora, la jornada natural dura 23 o 25 horas y el tramo no miente.
            'duration_minutes' => (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60),
            'status' => 'closed',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
            'superseded_by_id' => null,
            'created_at' => $end->format('Y-m-d H:i:sP'),
            'updated_at' => $end->format('Y-m-d H:i:sP'),
        ];
    }

    /**
     * Dos escaneos por tramo —el de entrada y el de salida— con la forma que
     * tendra el registro real: `occurred_at` es el momento del fichaje y
     * `recorded_at` la recepcion en el servidor (regla dura 9).
     *
     * @param  list<int>  $employeeIds
     * @param  list<int>  $deviceIds
     */
    private function seedScansFor(array $employeeIds, array $deviceIds): void
    {
        /** @var list<object{id: int, employee_id: int, clocked_in_at: string, clocked_out_at: string, duration_minutes: int}> $entries */
        $entries = DB::table('shift_entries')
            ->select(['id', 'employee_id', 'clocked_in_at', 'clocked_out_at', 'duration_minutes'])
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->get()
            ->all();

        $scans = [];

        foreach ($entries as $entry) {
            $device = $deviceIds[$entry->employee_id % \count($deviceIds)];

            $scans[] = $this->scanRow($entry->id, $entry->employee_id, $device, $entry->clocked_in_at, 'clock_in', 0);
            $scans[] = $this->scanRow($entry->id, $entry->employee_id, $device, $entry->clocked_out_at, 'clock_out', $entry->duration_minutes);
        }

        foreach (array_chunk($scans, 2_000) as $chunk) {
            DB::table('scan_events')->insert($chunk);
        }
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function scanRow(int $shiftEntryId, int $employeeId, int $deviceId, string $occurredAt, string $result, int $workedMinutes): array
    {
        return [
            // Regla dura 8: el UUID v7 del escaneo es lo que hace idempotente
            // el reenvio desde la cola offline.
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $deviceId,
            'employee_id' => $employeeId,
            'occurred_at' => $occurredAt,
            // En la semilla el quiosco estaba en linea: la recepcion es
            // inmediata. El retraso de la cola offline lo genera la tarea 1.9.
            'recorded_at' => $occurredAt,
            'origin' => 'qr_kiosk',
            'intent' => 'auto',
            'result' => $result,
            'shift_entry_id' => $shiftEntryId,
            'payload_fingerprint' => hash('sha256', 'kronoqr-seed-payload-'.$employeeId),
            'client_meta' => json_encode(['seeded' => true], JSON_THROW_ON_ERROR),
            'clock_skew_seconds' => 0,
            'flagged_for_review' => false,
            'worked_minutes' => $workedMinutes,
        ];
    }

    /**
     * Reconstruye `daily_totals` a partir de los tramos **vigentes**.
     *
     * Es una sola sentencia y es deliberado: la proyeccion se **recalcula** como
     * agregado de su origen (RN-06, ADR-007, regla dura 7). El predicado
     * `status NOT IN ('voided','superseded')` es el mismo de las dos
     * restricciones declarativas de `shift_entries` y el mismo que expresa
     * `ShiftEntryStatus::isCurrent()` en el dominio (ADR-026).
     */
    private function projectDailyTotals(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO daily_totals (
                employee_id, work_date, total_minutes, shift_count,
                first_in_at, last_out_at, has_open_shift, has_incident, recalculated_at
            )
            SELECT employee_id,
                   work_date,
                   COALESCE(SUM(duration_minutes), 0),
                   COUNT(*),
                   MIN(clocked_in_at),
                   MAX(clocked_out_at),
                   BOOL_OR(clocked_out_at IS NULL),
                   BOOL_OR(status = 'anomalous'),
                   NOW()
              FROM shift_entries
             WHERE status NOT IN ('voided', 'superseded')
             GROUP BY employee_id, work_date
            ON CONFLICT (employee_id, work_date) DO UPDATE
               SET total_minutes   = EXCLUDED.total_minutes,
                   shift_count     = EXCLUDED.shift_count,
                   first_in_at     = EXCLUDED.first_in_at,
                   last_out_at     = EXCLUDED.last_out_at,
                   has_open_shift  = EXCLUDED.has_open_shift,
                   has_incident    = EXCLUDED.has_incident,
                   recalculated_at = EXCLUDED.recalculated_at
        SQL);
    }
}
