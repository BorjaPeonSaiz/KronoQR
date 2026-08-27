<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * **Las duraciones y los minutos del dia estan escritos a mano.** Calculados con
 * la misma aritmetica que el codigo bajo prueba, un error de signo daria una
 * semilla «correcta» y las pruebas que la usen tambien.
 *
 * **No escribe en `audit_log`.** El asiento lo produce el listener de
 * `Compliance` al publicarse el evento de dominio, y una semilla que insertara
 * filas a mano en esa tabla romperia la cadena de hash (ADR-027). El trail de
 * una instalacion real se llena usando el producto, no sembrandolo.
 */
final class CorrectionSeeder extends Seeder
{
    private const string TIMEZONE = 'Europe/Madrid';

    /** Anexo C: `AJUSTE_ACORDADO_CON_RRHH` no exige texto; `OTROS` si. */
    private const string ADJUSTMENT_REASON = 'AJUSTE_ACORDADO_CON_RRHH';

    public function run(): void
    {
        $siteId = $this->madridSite();

        if ($siteId === null) {
            $this->command->warn('CorrectionSeeder: no hay ningun centro en '.self::TIMEZONE.'; no se siembra nada.');

            return;
        }

        $author = $this->author();

        if ($author === null) {
            $this->command->warn('CorrectionSeeder: no hay ninguna cuenta de gestion que firme; no se siembra nada.');

            return;
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $department = $this->departmentOf($siteId);

        $this->seedRectifiedClockOut($siteId, $department, $author, $timezone);
        $this->seedVoidedDuplicate($siteId, $department, $author, $timezone);
        $this->seedRetroactiveEntry($siteId, $department, $author, $timezone);

        $this->projectDailyTotals();

        $this->command->info('CorrectionSeeder: 3 correcciones sembradas, con sus versiones anteriores.');
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
        $employee = $this->employee(0, 'Lucia', 'Vilar', $siteId, $department);

        if ($employee === null) {
            return;
        }

        $in = $this->toUtc('2026-03-20 14:00', $timezone);

        // Version 1: lo que el quiosco registro. 14:00 -> 22:10 son 490 minutos.
        $original = $this->insertEntry([
            'employee_id' => $employee,
            'site_id' => $siteId,
            'work_date' => '2026-03-20',
            'clocked_in_at' => $in,
            'clocked_out_at' => $this->toUtc('2026-03-20 22:10', $timezone),
            'duration_minutes' => 490,
            'status' => 'superseded',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
        ]);

        // Version 2: la salida rectificada a 22:00. 480 minutos, no 970.
        $replacement = $this->insertEntry([
            'employee_id' => $employee,
            'site_id' => $siteId,
            'work_date' => '2026-03-20',
            'clocked_in_at' => $in,
            'clocked_out_at' => $this->toUtc('2026-03-20 22:00', $timezone),
            'duration_minutes' => 480,
            'status' => 'closed',
            // La entrada la ficho la persona con su tarjeta y eso no ha dejado de
            // ser verdad: solo la marca que cambia pasa a `manual_admin`.
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'manual_admin',
            'version' => 2,
        ]);

        if ($original === null || $replacement === null) {
            return;
        }

        // El puntero que hace recorrible el historico (RL-04). Se escribe
        // DESPUES de insertar la version nueva, porque es una clave foranea.
        DB::table('shift_entries')->where('id', $original)->update(['superseded_by_id' => $replacement]);

        $this->insertCorrection([
            // Apunta a la version que la accion PRODUJO.
            'shift_entry_id' => $replacement,
            'performed_by_user_id' => $author,
            'action' => 'modified',
            'before' => $this->marks(1, '2026-03-20 14:00', '2026-03-20 22:10', 490, $timezone),
            'after' => $this->marks(2, '2026-03-20 14:00', '2026-03-20 22:00', 480, $timezone),
            'reason_code' => self::ADJUSTMENT_REASON,
            'reason_text' => 'La persona salio a las 22:00; el escaneo se hizo al recoger.',
            'created_at' => $this->toUtc('2026-03-21 09:15', $timezone),
        ]);
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
        $employee = $this->employee(1, 'Tomas', 'Bergara', $siteId, $department);

        if ($employee === null) {
            return;
        }

        $this->insertEntry([
            'employee_id' => $employee,
            'site_id' => $siteId,
            'work_date' => '2026-03-21',
            'clocked_in_at' => $this->toUtc('2026-03-21 06:00', $timezone),
            'clocked_out_at' => $this->toUtc('2026-03-21 14:00', $timezone),
            'duration_minutes' => 480,
            'status' => 'closed',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
        ]);

        // El sobrante: quince minutos que nadie trabajo, producto de dos escaneos
        // seguidos. Solapa con el anterior, y solo cabe en la tabla por estar
        // anulado.
        $duplicate = $this->insertEntry([
            'employee_id' => $employee,
            'site_id' => $siteId,
            'work_date' => '2026-03-21',
            'clocked_in_at' => $this->toUtc('2026-03-21 13:45', $timezone),
            'clocked_out_at' => $this->toUtc('2026-03-21 14:00', $timezone),
            'duration_minutes' => 15,
            'status' => 'voided',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
        ]);

        if ($duplicate === null) {
            return;
        }

        $this->insertCorrection([
            // En una anulacion apunta a la version que TERMINO.
            'shift_entry_id' => $duplicate,
            'performed_by_user_id' => $author,
            'action' => 'voided',
            'before' => $this->marks(1, '2026-03-21 13:45', '2026-03-21 14:00', 15, $timezone),
            'after' => null,
            'reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO',
            'reason_text' => null,
            'created_at' => $this->toUtc('2026-03-22 08:30', $timezone),
        ]);
    }

    /**
     * Un tramo que nunca se ficho, dado de alta a mano.
     *
     * Es el dia anterior a la puesta en marcha del quiosco, o el primero de
     * alguien a quien todavia no se le habia entregado la tarjeta (ADR-034).
     * Vale para la nomina igual que uno escaneado; lo que lo distingue es el
     * origen y la fila que lo explica.
     */
    private function seedRetroactiveEntry(int $siteId, ?int $department, int $author, DateTimeZone $timezone): void
    {
        $employee = $this->employee(2, 'Nerea', 'Calafell', $siteId, $department);

        if ($employee === null) {
            return;
        }

        $entry = $this->insertEntry([
            'employee_id' => $employee,
            'site_id' => $siteId,
            'work_date' => '2026-03-19',
            'clocked_in_at' => $this->toUtc('2026-03-19 08:00', $timezone),
            'clocked_out_at' => $this->toUtc('2026-03-19 16:00', $timezone),
            'duration_minutes' => 480,
            'status' => 'closed',
            'clock_in_source' => 'manual_admin',
            'clock_out_source' => 'manual_admin',
            'version' => 1,
        ]);

        if ($entry === null) {
            return;
        }

        $this->insertCorrection([
            'shift_entry_id' => $entry,
            'performed_by_user_id' => $author,
            'action' => 'created',
            // En un alta no hay valor anterior: antes no habia tramo.
            'before' => null,
            'after' => $this->marks(1, '2026-03-19 08:00', '2026-03-19 16:00', 480, $timezone),
            'reason_code' => 'ALTA_RETROACTIVA',
            'reason_text' => 'Jornada anterior a la puesta en marcha del quiosco.',
            'created_at' => $this->toUtc('2026-03-20 10:00', $timezone),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return int|null La clave interna del tramo, o `null` si ya existia.
     */
    private function insertEntry(array $row): ?int
    {
        $uuid = Str::uuid7()->toString();

        $written = DB::table('shift_entries')->insertOrIgnore([
            'uuid' => $uuid,
            ...$row,
            'created_at' => $row['clocked_in_at'],
            'updated_at' => $row['clocked_out_at'] ?? $row['clocked_in_at'],
        ]);

        if ($written === 0) {
            return null;
        }

        $id = DB::table('shift_entries')->where('uuid', $uuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertCorrection(array $row): void
    {
        DB::table('shift_corrections')->insert($row);
    }

    /**
     * Las marcas de una version, en la misma forma que escribe el adaptador del
     * libro de correcciones: version, dos instantes en UTC con sufijo `Z`, y los
     * minutos ya calculados.
     *
     * **Ni un nombre dentro** (regla dura 21): el JSON describe horas, y quien
     * las trabajo esta en el tramo.
     */
    private function marks(int $version, string $in, ?string $out, int $minutes, DateTimeZone $timezone): string
    {
        return json_encode([
            'version' => $version,
            'clocked_in_at' => $this->toIso($in, $timezone),
            'clocked_out_at' => $out === null ? null : $this->toIso($out, $timezone),
            'worked_minutes' => $minutes,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * El empleado del caso, creado si no existe.
     *
     * Codigo **opaco y estable**, derivado de un hash: nunca del nombre ni de un
     * numero secuencial (doc 01 §5.5). Que sea estable es lo que hace la semilla
     * repetible.
     */
    private function employee(int $index, string $first, string $last, int $siteId, ?int $departmentId): ?int
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

        $id = DB::table('employees')->where('employee_code', $code)->value('id');

        return is_numeric($id) ? (int) $id : null;
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
    private function toUtc(string $localWallClock, DateTimeZone $timezone): string
    {
        return (new DateTimeImmutable($localWallClock, $timezone))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:sP');
    }

    private function toIso(string $localWallClock, DateTimeZone $timezone): string
    {
        return (new DateTimeImmutable($localWallClock, $timezone))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
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
