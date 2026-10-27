<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Command\CorrectShiftEntryCommand;
use App\Modules\Attendance\Application\Command\VoidShiftEntryCommand;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\VoidShiftEntryHandler;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use Database\Seeders\Support\KioskClocking;
use Database\Seeders\Support\KioskClockingAudit;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Correcciones trazadas y tramos en estado `superseded` (doc 02 §10.2, tarea
 * 1.15, RN-13, RL-04).
 *
 * *«Un dataset de datos "bonitos" oculta exactamente los errores que este
 * dominio produce.»* {@see EdgeCaseSeeder} siembra las jornadas que rompen un
 * calculo de horas; esta siembra las que rompen una **consulta**: cualquier
 * informe, panel o exportacion que olvide filtrar por `status NOT IN ('voided',
 * 'superseded')` cuenta el doble de minutos, y ese error no aparece hasta que
 * hay una correccion en la base de datos. Con esta semilla, aparece el primer
 * dia.
 *
 * ## Los tres casos
 *
 * | Caso | Que rompe si esta mal | Regla |
 * |---|---|---|
 * | Salida rectificada | El dia suma las dos versiones y duplica los minutos | RN-06, RN-13, ADR-026 |
 * | Doble escaneo anulado | El tramo anulado sigue contando, o se borro la fila | RF-PA-04, regla dura 5 |
 * | Alta retroactiva | Un tramo `manual_admin` sin fila que lo explique | RN-13, RL-04 |
 *
 * **Empleados propios**, por lo mismo que en {@see EdgeCaseSeeder}: asi cada
 * caso se lee solo y ninguno depende del orden de las semillas ni puede solapar
 * con las jornadas de nadie (`shift_entries_no_overlap`).
 *
 * ## Las correcciones van por los casos de uso (bloque 11 de la 2.2.1)
 *
 * Corregir, anular y dar de alta lo hacen `CorrectShiftEntryHandler`,
 * `VoidShiftEntryHandler` y `AddShiftEntryHandler`, los mismos que usa el panel. Asi
 * la version nueva, el puntero `superseded_by_id`, la fila de
 * `shift_corrections`, el recalculo de `daily_totals` y el asiento de
 * `audit_log` son los del producto, en su transaccion y con la cadena de hash
 * intacta (ADR-027). Antes esta semilla escribia las cuatro tablas a mano y
 * ningun asiento, y `compliance:reconcile-work-record` lo daba por manipulacion
 * (ADR-057 §4).
 *
 * Lo que una correccion necesita delante —el tramo que ficho el quiosco— se
 * siembra como los demas tramos de quiosco: la fila y sus asientos de entrada y
 * salida ({@see KioskClockingAudit}), con la duracion **escrita a mano**.
 *
 * **El momento de la correccion es el de la siembra.** Los casos de uso lo
 * toman del puerto `Clock`, y no se finge otro: `shift_corrections.created_at`
 * y el asiento dicen cuando se sembro, que es cuando de verdad se corrigio.
 *
 * **Repetible.** Un caso cuyo empleado ya tiene tramos no se vuelve a sembrar:
 * una segunda correccion sobre el mismo tramo seria otro dato, no el mismo.
 */
final class CorrectionSeeder extends DevelopmentSeeder
{
    private const string TIMEZONE = 'Europe/Madrid';

    public function run(): void
    {
        $siteId = $this->madridSite();

        if ($siteId === null) {
            $this->command?->warn('CorrectionSeeder: no hay ningun centro en '.self::TIMEZONE.'; no se siembra nada.');

            return;
        }

        $author = $this->author();

        if ($author === null) {
            $this->command?->warn('CorrectionSeeder: no hay ninguna cuenta de gestion que firme; no se siembra nada.');

            return;
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $department = $this->departmentOf($siteId);

        $this->seedRectifiedClockOut($siteId, $department, $author, $timezone);
        $this->seedVoidedDuplicate($siteId, $department, $author, $timezone);
        $this->seedRetroactiveEntry($siteId, $department, $author);

        // Los casos de uso recalculan la jornada que tocan; el tramo bueno del
        // doble escaneo entra despues de la anulacion y por fuera de ellos.
        $this->projectDailyTotals();

        $this->command?->info('CorrectionSeeder: 3 correcciones sembradas, con sus versiones anteriores.');
    }

    /**
     * Una salida rectificada: la version 1 queda `superseded` y apunta a la 2.
     *
     * Es el caso que justifica que `superseded` exista (ADR-026): las dos filas
     * conviven, sus intervalos **se solapan**, y solo la restriccion de
     * exclusion filtrada por estado permite que las dos esten en la tabla.
     */
    private function seedRectifiedClockOut(int $siteId, ?int $department, int $author, DateTimeZone $timezone): void
    {
        $employee = $this->freshEmployee(0, 'Lucia', 'Vilar', $siteId, $department);

        if ($employee === null) {
            return;
        }

        // Version 1: lo que el quiosco registro. 14:00 -> 22:10 son 490 minutos.
        $original = $this->kioskEntry($employee, $siteId, '2026-03-20', '2026-03-20 14:00', '2026-03-20 22:10', 490, $timezone);

        // Version 2: la salida rectificada a 22:00. 480 minutos, no 970. La
        // entrada la ficho la persona con su tarjeta y eso no deja de ser verdad:
        // solo la marca que cambia pasa a `manual_admin`.
        resolve(CorrectShiftEntryHandler::class)->handle(new CorrectShiftEntryCommand(
            shiftEntryUuid: $original,
            clockedInAt: null,
            clockedOutAt: $this->toUtc('2026-03-20 22:00', $timezone),
            reason: CorrectionReason::fromCode(
                'AJUSTE_ACORDADO_CON_RRHH',
                'La persona salio a las 22:00; el escaneo se hizo al recoger.',
            ),
            performedByUserId: $author,
        ));
    }

    /**
     * Un doble escaneo anulado: la fila se queda, y deja de contar.
     *
     * Anular no crea version nueva y no pone `superseded_by_id`: no hay version
     * posterior de un hecho que no paso (ADR-026). La jornada conserva su tramo
     * bueno y el dia suma 480, no 495.
     */
    private function seedVoidedDuplicate(int $siteId, ?int $department, int $author, DateTimeZone $timezone): void
    {
        $employee = $this->freshEmployee(1, 'Tomas', 'Bergara', $siteId, $department);

        if ($employee === null) {
            return;
        }

        // El sobrante **primero**: quince minutos que nadie trabajo, producto de
        // dos escaneos seguidos. Solo anulado deja sitio al tramo bueno, con el
        // que solapa.
        $duplicate = $this->kioskEntry($employee, $siteId, '2026-03-21', '2026-03-21 13:45', '2026-03-21 14:00', 15, $timezone);

        resolve(VoidShiftEntryHandler::class)->handle(new VoidShiftEntryCommand(
            shiftEntryUuid: $duplicate,
            reason: CorrectionReason::fromCode('ERROR_DE_ESCANEO_DUPLICADO'),
            performedByUserId: $author,
        ));

        $this->kioskEntry($employee, $siteId, '2026-03-21', '2026-03-21 06:00', '2026-03-21 14:00', 480, $timezone);
    }

    /**
     * Un tramo que nunca se ficho, dado de alta a mano.
     *
     * Es el dia anterior a la puesta en marcha del quiosco, o el primero de
     * alguien a quien todavia no se le habia entregado la tarjeta (ADR-034).
     * Vale para la nomina igual que uno escaneado; lo que lo distingue es el
     * origen y la fila que lo explica.
     */
    private function seedRetroactiveEntry(int $siteId, ?int $department, int $author): void
    {
        $employee = $this->freshEmployee(2, 'Nerea', 'Calafell', $siteId, $department);

        if ($employee === null) {
            return;
        }

        $timezone = new DateTimeZone(self::TIMEZONE);

        resolve(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
            employeeUuid: $employee['uuid'],
            workDate: '2026-03-19',
            clockedInAt: $this->toUtc('2026-03-19 08:00', $timezone),
            clockedOutAt: $this->toUtc('2026-03-19 16:00', $timezone),
            reason: CorrectionReason::fromCode('ALTA_RETROACTIVA', 'Jornada anterior a la puesta en marcha del quiosco.'),
            performedByUserId: $author,
        ));
    }

    /**
     * Un tramo cerrado que ficho el quiosco, con sus asientos de entrada y
     * salida, en una transaccion (regla dura 6, ADR-057 §4).
     *
     * @param  array{id: int, uuid: string}  $employee
     * @return string El `uuid` del tramo.
     */
    private function kioskEntry(
        array $employee,
        int $siteId,
        string $workDate,
        string $in,
        string $out,
        int $minutes,
        DateTimeZone $timezone,
    ): string {
        $uuid = Str::uuid7()->toString();
        $clockedInAt = $this->toUtc($in, $timezone);
        $clockedOutAt = $this->toUtc($out, $timezone);

        // Las particiones de `audit_log` antes de abrir la transaccion: el DDL va
        // por la conexion del rol de migracion (ver KioskClockingAudit).
        resolve(KioskClockingAudit::class)->preparePartitionsBetween($clockedInAt, $clockedOutAt);

        DB::transaction(function () use ($uuid, $employee, $siteId, $workDate, $clockedInAt, $clockedOutAt, $minutes, $timezone): void {
            DB::table('shift_entries')->insert([
                'uuid' => $uuid,
                'employee_id' => $employee['id'],
                'site_id' => $siteId,
                'work_date' => $workDate,
                'clocked_in_at' => $clockedInAt->format('Y-m-d H:i:sP'),
                'clocked_out_at' => $clockedOutAt->format('Y-m-d H:i:sP'),
                'duration_minutes' => $minutes,
                'status' => 'closed',
                'clock_in_source' => 'qr_kiosk',
                'clock_out_source' => 'qr_kiosk',
                'version' => 1,
                'created_at' => $clockedInAt->format('Y-m-d H:i:sP'),
                'updated_at' => $clockedOutAt->format('Y-m-d H:i:sP'),
            ]);

            resolve(KioskClockingAudit::class)->record([
                new KioskClocking(
                    employeeUuid: $employee['uuid'],
                    siteId: $siteId,
                    shiftEntryUuid: $uuid,
                    workDate: $workDate,
                    timezone: $timezone,
                    clockedInAt: $clockedInAt,
                    clockedOutAt: $clockedOutAt,
                    dailyTotalMinutes: $this->dailyTotal($employee['id'], $workDate),
                ),
            ]);
        });

        return $uuid;
    }

    /**
     * Minutos vigentes de la jornada, contando el tramo recien insertado: lo que
     * el fichaje apunta en el asiento de la salida (RN-06).
     */
    private function dailyTotal(int $employeeId, string $workDate): int
    {
        return (int) DB::table('shift_entries')
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->whereNotIn('status', ['voided', 'superseded'])
            ->sum('duration_minutes');
    }

    /**
     * El empleado del caso, creado si no existe, o `null` si ya tiene tramos: el
     * caso ya esta sembrado.
     *
     * Codigo **opaco y estable**, derivado de un hash: nunca del nombre ni de un
     * numero secuencial (doc 01 §5.5). Que sea estable es lo que hace la semilla
     * repetible.
     *
     * @return array{id: int, uuid: string}|null
     */
    private function freshEmployee(int $index, string $first, string $last, int $siteId, ?int $departmentId): ?array
    {
        $code = 'E'.mb_strtoupper(mb_substr(hash('sha256', 'kronoqr-seed-correction-'.$index), 0, 9));

        DB::table('employees')->insertOrIgnore([
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $siteId,
            'department_id' => $departmentId,
            'first_name' => $first,
            'last_name' => $last,
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

        $row = DB::table('employees')->where('employee_code', $code)->first(['id', 'uuid']);

        if ($row === null || ! is_numeric($row->id) || ! \is_string($row->uuid)) {
            throw new RuntimeException('CorrectionSeeder: the employee of a correction case could not be read back.');
        }

        if (DB::table('shift_entries')->where('employee_id', (int) $row->id)->exists()) {
            return null;
        }

        return ['id' => (int) $row->id, 'uuid' => $row->uuid];
    }

    /**
     * Quien firma las correcciones sembradas.
     *
     * Una cuenta de gestion real de la semilla, no un identificador inventado:
     * `performed_by_user_id` es una clave foranea, y RN-13 exige que el autor
     * sea una persona. Si no hubiera ninguna, esta semilla no siembra nada en
     * vez de escribir «lo hizo el sistema».
     */
    private function author(): ?int
    {
        $id = DB::table('users')->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

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

    /**
     * Hora local del centro convertida a UTC (regla dura 3).
     *
     * Se construye la hora **local** y se convierte, y no al reves: restando
     * horas a un instante UTC, los turnos se desplazarian una hora en cada
     * cambio de hora.
     */
    private function toUtc(string $localWallClock, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($localWallClock, $timezone)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Recalcula `daily_totals` de las jornadas sembradas aqui.
     *
     * La misma sentencia que {@see EdgeCaseSeeder} y la misma forma que el
     * recalculo del caso de uso: se **agrega** desde los tramos vigentes, nunca
     * se incrementa (RN-06, ADR-007, regla dura 7).
     *
     * El `WHERE` con los dos estados no vigentes es justo lo que esta semilla
     * viene a ejercitar: sin el, la jornada de la salida rectificada sumaria 970
     * minutos y la del doble escaneo 495.
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
