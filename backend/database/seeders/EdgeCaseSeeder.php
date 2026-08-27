<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los **casos limite** del doc 02 §10.2: turnos nocturnos que cruzan la
 * medianoche, los dos dias de cambio de hora en `Europe/Madrid` y un olvido de
 * salida.
 *
 * *«Un dataset de datos "bonitos" oculta exactamente los errores que este
 * dominio produce.»* {@see VolumeSeeder} genera noventa dias de jornadas
 * deliberadamente aburridas —manana, tarde y partida, sin solapes— para que
 * medir el esquema tenga sentido. Aqui esta lo otro: las cuatro formas que hacen
 * fallar un calculo de horas mal escrito, y que en produccion aparecen la
 * primera semana.
 *
 * **Esta semilla es de la tarea 1.4 y no de la 1.3** porque es quien implementa
 * el fichaje la que sabe que caso limite produce cada error. Un esquema sabe
 * que `work_date` es un `DATE`; solo el caso de uso sabe que atribuirlo mal
 * reparte un turno de noche entre dos dias en la nomina.
 *
 * ## Los cuatro casos
 *
 * | Caso | Que rompe si esta mal | Regla |
 * |---|---|---|
 * | Turno 22:00 -> 06:00 | Se parte en dos tramos y la jornada se atribuye al dia siguiente | RN-05, RF-AT-08, regla dura 4 |
 * | Cambio de hora de primavera | La hora que no existe se cuenta igual: 8 h de reloj, 7 reales | RN-09 |
 * | Cambio de hora de otono | La hora repetida no se cuenta: 8 h de reloj, 9 reales | RN-09 |
 * | Olvido de salida | El tramo se cierra solo, o desaparece del calculo | RN-08, RF-PR-01 |
 *
 * **Las duraciones estan escritas a mano y a proposito.** Si se calcularan con
 * la misma aritmetica que el codigo bajo prueba, un error de signo daria una
 * semilla «correcta» y las pruebas que la usen tambien.
 *
 * **Empleados propios.** Los cuatro casos usan personas creadas aqui, y no
 * empleados de {@see EmployeeSeeder}: un tramo abierto sin salida tiene rango
 * abierto por arriba y solaparia con cualquier jornada posterior de esa persona
 * (`shift_entries_no_overlap`). Con empleados propios, cada caso limite se lee
 * solo y ninguno depende del orden de las semillas.
 */
final class EdgeCaseSeeder extends Seeder
{
    private const string TIMEZONE = 'Europe/Madrid';

    /**
     * Los cuatro casos, con su duracion **escrita**, no calculada.
     *
     * `null` en la salida es el olvido de fichaje: el tramo se queda abierto y
     * **nadie lo cierra automaticamente** (RN-08, escenario «Turno olvidado» del
     * doc 01 §11). La incidencia `open_shift_expired` que lo trabaja es de la
     * Fase 3.
     *
     * @var list<array{
     *     first: string, last: string, work_date: string,
     *     in: string, out: string|null, minutes: int|null, note: string
     * }>
     */
    private const array CASES = [
        [
            'first' => 'Marc',
            'last' => 'Nogueira',
            'work_date' => '2026-03-14',
            // Hora LOCAL del centro. La conversion a UTC la hace el seeder, que
            // es donde tiene que ocurrir (regla dura 3).
            'in' => '2026-03-14 22:00',
            'out' => '2026-03-15 06:00',
            'minutes' => 480,
            'note' => 'Turno nocturno: un solo tramo, atribuido al dia de inicio (RN-05).',
        ],
        [
            'first' => 'Ainhoa',
            'last' => 'Quiroga',
            'work_date' => '2026-03-28',
            // El ultimo domingo de marzo los relojes saltan de 02:00 a 03:00: la
            // jornada dura 8 h de reloj y 7 reales.
            'in' => '2026-03-28 23:00',
            'out' => '2026-03-29 07:00',
            'minutes' => 420,
            'note' => 'Cambio de hora de primavera: 8 h de reloj, 7 reales (RN-09).',
        ],
        [
            'first' => 'Ruben',
            'last' => 'Escudero',
            'work_date' => '2026-10-24',
            // El ultimo domingo de octubre las 02:00 ocurren dos veces: 8 h de
            // reloj y 9 reales.
            'in' => '2026-10-24 23:00',
            'out' => '2026-10-25 07:00',
            'minutes' => 540,
            'note' => 'Cambio de hora de otono: 8 h de reloj, 9 reales (RN-09).',
        ],
        [
            'first' => 'Ana',
            'last' => 'Bustos',
            'work_date' => '2026-03-14',
            'in' => '2026-03-14 06:00',
            'out' => null,
            'minutes' => null,
            'note' => 'Olvido de salida: el tramo NO se cierra solo (RN-08, RF-PR-01).',
        ],
    ];

    public function run(): void
    {
        $siteId = $this->madridSite();

        if ($siteId === null) {
            $this->command->warn('EdgeCaseSeeder: no hay ningun centro en '.self::TIMEZONE.'; no se siembra nada.');

            return;
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $device = $this->deviceOf($siteId);
        $department = $this->departmentOf($siteId);

        foreach (self::CASES as $index => $case) {
            $this->seedCase($index, $case, $siteId, $department, $device, $timezone);
        }

        $this->projectDailyTotals();

        $this->command->info('EdgeCaseSeeder: '.\count(self::CASES).' casos limite sembrados.');
    }

    /**
     * @param  array{first: string, last: string, work_date: string, in: string, out: string|null, minutes: int|null, note: string}  $case
     */
    private function seedCase(int $index, array $case, int $siteId, ?int $departmentId, ?int $deviceId, DateTimeZone $timezone): void
    {
        $employeeId = $this->employee($index, $case, $siteId, $departmentId);

        if ($employeeId === null) {
            return;
        }

        $in = $this->toUtc($case['in'], $timezone);
        $out = $case['out'] === null ? null : $this->toUtc($case['out'], $timezone);

        $entryUuid = Str::uuid7()->toString();

        $written = DB::table('shift_entries')->insertOrIgnore([
            'uuid' => $entryUuid,
            'employee_id' => $employeeId,
            'site_id' => $siteId,
            'work_date' => $case['work_date'],
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
            'duration_minutes' => $case['minutes'],
            // `open` mientras no haya salida: el olvido de fichaje se queda asi
            // hasta que una persona lo corrija (RN-08, regla dura 5).
            'status' => $out === null ? 'open' : 'closed',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => $out === null ? null : 'qr_kiosk',
            'version' => 1,
            'created_at' => $in,
            'updated_at' => $out ?? $in,
        ]);

        if ($written === 0 || $deviceId === null) {
            return;
        }

        $entryId = DB::table('shift_entries')->where('uuid', $entryUuid)->value('id');

        $this->seedScan($entryId, $employeeId, $deviceId, $in, 'clock_in');

        if ($out !== null) {
            $this->seedScan($entryId, $employeeId, $deviceId, $out, 'clock_out');
        }
    }

    /**
     * El empleado del caso, creado si no existe.
     *
     * El codigo es **opaco y estable**: se deriva de un hash del indice, nunca
     * del nombre ni de un numero secuencial (doc 01 §5.5). Que sea estable es lo
     * que hace la semilla repetible.
     *
     * @param  array{first: string, last: string, work_date: string, in: string, out: string|null, minutes: int|null, note: string}  $case
     */
    private function employee(int $index, array $case, int $siteId, ?int $departmentId): ?int
    {
        $code = 'E'.mb_strtoupper(mb_substr(hash('sha256', 'kronoqr-seed-edge-case-'.$index), 0, 9));

        DB::table('employees')->insertOrIgnore([
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $siteId,
            'department_id' => $departmentId,
            'first_name' => $case['first'],
            'last_name' => $case['last'],
            'employee_code' => $code,
            'email' => null,
            'photo_path' => null,
            'status' => 'active',
            'hired_at' => '2025-01-01',
            'terminated_at' => null,
            'locale' => 'es',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('employees')->where('employee_code', $code)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function seedScan(mixed $entryId, int $employeeId, int $deviceId, string $at, string $result): void
    {
        DB::table('scan_events')->insertOrIgnore([
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $deviceId,
            'employee_id' => $employeeId,
            'occurred_at' => $at,
            // En la semilla el quiosco estaba en linea: la recepcion es
            // inmediata. El retraso de la cola offline lo genera la tarea 1.9.
            'recorded_at' => $at,
            'origin' => 'qr_kiosk',
            'intent' => 'auto',
            'result' => $result,
            'shift_entry_id' => is_numeric($entryId) ? (int) $entryId : null,
            'payload_fingerprint' => hash('sha256', 'kronoqr-seed-edge-payload-'.$employeeId),
            'client_meta' => json_encode(['seeded' => true, 'edge_case' => true], JSON_THROW_ON_ERROR),
            'clock_skew_seconds' => 0,
            'flagged_for_review' => false,
            // `clock_in`/`clock_out` siempre, nunca un rechazo real: la semilla
            // no ejercita el acumulado y `scan_events_chk_worked_minutes` solo
            // exige que no sea nulo aqui.
            'worked_minutes' => 0,
        ]);
    }

    /**
     * Hora local del centro convertida a UTC (regla dura 3).
     *
     * Se construye la hora **local** y se convierte, y no al reves: restando
     * horas a un instante UTC, el turno se desplazaria una hora en cada cambio
     * de hora, que es justo lo que estos casos vienen a detectar.
     */
    private function toUtc(string $localWallClock, DateTimeZone $timezone): string
    {
        return (new DateTimeImmutable($localWallClock, $timezone))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:sP');
    }

    /**
     * El primer centro en la zona de los casos limite.
     *
     * `Europe/Madrid` y no cualquiera: los dos cambios de hora de esta semilla
     * son los suyos, y sembrarlos contra un centro en `Atlantic/Canary` daria
     * unas horas que no corresponden a ninguna jornada real.
     */
    private function madridSite(): ?int
    {
        $id = DB::table('sites')->where('timezone', self::TIMEZONE)->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function departmentOf(int $siteId): ?int
    {
        $id = DB::table('departments')->where('site_id', $siteId)->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function deviceOf(int $siteId): ?int
    {
        $id = DB::table('devices')->where('site_id', $siteId)->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Recalcula `daily_totals` de las jornadas sembradas aqui.
     *
     * Es la misma sentencia que usa {@see VolumeSeeder} y la misma forma que el
     * recalculo del caso de uso: la proyeccion se **agrega** desde los tramos
     * vigentes, nunca se incrementa (RN-06, ADR-007, regla dura 7). Que la
     * semilla la construya igual que el codigo es lo que permite comparar las
     * dos consultas de la reconciliacion nada mas sembrar.
     *
     * `BOOL_OR(clocked_out_at IS NULL)` deja el olvido de salida marcado como
     * jornada abierta, que es lo que el panel de presencia tiene que mostrar.
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
