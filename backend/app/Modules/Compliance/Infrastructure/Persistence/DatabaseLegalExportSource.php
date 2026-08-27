<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Domain\ValueObject\ExportedCorrection;
use App\Modules\Compliance\Domain\ValueObject\ExportedDuration;
use App\Modules\Compliance\Domain\ValueObject\ExportedMarks;
use App\Modules\Compliance\Domain\ValueObject\ExportedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\ExportedSubject;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportRecord;
use App\Modules\Compliance\Domain\ValueObject\LegalExportRecordType;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * La consulta que produce el registro diario por trabajador y periodo
 * (RF-IN-05, RL-03, RL-06, plan 1.17 pasos 2 y 3).
 *
 * ## Lee `shift_entries` y `shift_corrections` sin tocar `Attendance`
 *
 * Las dos tablas son del nucleo, pero este modulo **no puede importar nada de
 * `Attendance`** (doc 02 §1.6, verificado por Deptrac). Y no debe: lo que
 * necesita no es el agregado `WorkDay` con sus invariantes, es un modelo de
 * lectura plano. Por eso aqui hay SQL y no Eloquent — un modelo de otro modulo
 * seria una dependencia prohibida, y uno propio sobre la misma tabla seria una
 * segunda definicion del mismo esquema.
 *
 * ## `AT TIME ZONE` de la zona del centro, nunca UTC en crudo
 *
 * Un inspector lee el documento con el horario del contrato delante. Entregarle
 * `05:00Z` por una entrada a las siete de la mañana en Madrid convierte una
 * comprobacion trivial en una discusion. La zona sale de `sites.timezone`, fila
 * a fila, porque una instalacion puede tener centros en husos distintos —Madrid
 * y Canarias son el mismo pais y una hora de diferencia—. La marca UTC va
 * **ademas**, no en lugar de: es la almacenada (regla dura 3) y la unica que no
 * se repite ni se salta en el cambio de hora.
 *
 * ## Que filas entran
 *
 * - **Tramos vigentes y anulados.** Nada se oculta (regla dura 5): un tramo
 *   anulado figura con su estado y no suma horas.
 * - **Versiones sustituidas, no.** Lo que decian esta en la columna «antes» de
 *   la correccion que las sustituyo; repetirlas contaria dos veces el mismo
 *   trabajo.
 * - **Todas las correcciones del periodo**, incluidas las que recaen sobre
 *   versiones ya sustituidas — de ahi que la segunda rama del `UNION` se una a
 *   `scope` y no a `entries`. Es la historia completa, que es lo que RL-04 pide.
 *
 * Un turno de noche sale como **un solo tramo**, en la jornada en la que empezo:
 * el filtro es por `work_date` y no por la fecha civil de las marcas (RN-05,
 * regla dura 4).
 *
 * ## Cursor de servidor, no un `SELECT` completo
 *
 * `DECLARE ... CURSOR` y `FETCH FORWARD` por lotes. Es la unica forma real de
 * cumplir lo que el doc 02 §3.1 exige de las exportaciones —*«no carga en
 * memoria un mes de 500 empleados»*—: el driver de PostgreSQL trae al cliente el
 * resultado **entero** de un `SELECT` normal, asi que un generador sobre
 * `cursor()` seria streaming de mentira, con las decenas de miles de filas ya en
 * memoria antes de ceder la primera. Con el cursor de servidor, en el proceso
 * solo hay un lote cada vez.
 *
 * Un cursor sin `HOLD` **exige transaccion**, y la abre el caso de uso. Es la
 * misma transaccion que hace que lo exportado sea el registro en un instante y
 * no un promedio de los segundos que tarde la escritura.
 *
 * ## Las columnas se leen por {@see Row}, no con `(int)`
 *
 * PDO no promete el tipo PHP de una columna: un `integer` de PostgreSQL llega
 * como `int` o como `string` segun como este compilado el driver. Aqui se
 * escribia `(int) $row->day_minutes` confiando en una anotacion `@var` que
 * PHPStan cree y el driver no garantiza — y un `(int)` sobre una columna nula
 * devuelve un cero que **parece un dato**. En el total de una jornada que acaba
 * en un documento con efectos legales, un cero silencioso es peor que una
 * excepcion: `Row` distingue nulo de cero y falla en voz alta cuando falta una
 * columna obligatoria.
 */
final readonly class DatabaseLegalExportSource implements LegalExportSource
{
    /**
     * Filas por `FETCH`. Cinco centenares es un lote que cabe de sobra en
     * memoria y ahorra ida y vuelta: el numero no cambia el resultado, solo
     * cuantas veces se habla con la base de datos.
     */
    private const int FETCH_SIZE = 500;

    /**
     * La consulta. Se escribe entera aqui, una vez, porque las dos ramas del
     * `UNION` tienen que producir exactamente las mismas columnas y en el mismo
     * orden: partirla en dos metodos que se concatenan es la forma mas comoda de
     * que un dia dejen de coincidir.
     *
     * `ORDER BY` al final y sobre nombres de columna de salida, que es lo unico
     * que admite un `UNION`. El orden es contrato del puerto: trabajador,
     * jornada, primero los tramos y despues las correcciones.
     */
    private const string SQL = <<<'SQL'
        WITH scope AS (
            SELECT se.id,
                   se.uuid              AS shift_entry_uuid,
                   se.employee_id,
                   se.work_date,
                   se.clocked_in_at,
                   se.clocked_out_at,
                   se.duration_minutes,
                   se.status,
                   se.clock_in_source,
                   se.clock_out_source,
                   e.uuid               AS employee_uuid,
                   e.employee_code,
                   e.last_name,
                   e.first_name,
                   st.name              AS site_name,
                   st.timezone          AS site_timezone,
                   dp.name              AS department_name
              FROM shift_entries se
              JOIN employees    e  ON e.id  = se.employee_id
              JOIN sites        st ON st.id = se.site_id
              LEFT JOIN departments dp ON dp.id = e.department_id
             WHERE se.work_date BETWEEN CAST(? AS date) AND CAST(? AS date)
               AND (CAST(? AS uuid) IS NULL OR e.uuid = CAST(? AS uuid))
        ),
        entries AS (
            SELECT s.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY s.employee_id, s.work_date
                       ORDER BY s.clocked_in_at, s.id
                   ) AS entry_number,
                   COALESCE(
                       SUM(s.duration_minutes) FILTER (WHERE s.status <> 'voided')
                           OVER (PARTITION BY s.employee_id, s.work_date),
                       0
                   ) AS day_minutes
              FROM scope s
             WHERE s.status <> 'superseded'
        )
        SELECT 'shift_entry'::text                     AS record_type,
               en.employee_code::text                  AS employee_code,
               en.last_name::text                      AS last_name,
               en.first_name::text                     AS first_name,
               en.employee_uuid::text                  AS employee_uuid,
               en.site_name::text                      AS site_name,
               en.department_name::text                AS department_name,
               en.site_timezone::text                  AS site_timezone,
               to_char(en.work_date, 'YYYY-MM-DD')     AS work_date,
               en.entry_number                         AS entry_number,
               en.shift_entry_uuid::text               AS shift_entry_uuid,
               to_char(en.clocked_in_at  AT TIME ZONE en.site_timezone, 'YYYY-MM-DD HH24:MI')   AS local_in,
               to_char(en.clocked_out_at AT TIME ZONE en.site_timezone, 'YYYY-MM-DD HH24:MI')   AS local_out,
               to_char(en.clocked_in_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')      AS utc_in,
               to_char(en.clocked_out_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')      AS utc_out,
               en.duration_minutes                     AS duration_minutes,
               en.day_minutes                          AS day_minutes,
               en.status::text                         AS status,
               en.clock_in_source::text                AS clock_in_source,
               en.clock_out_source::text               AS clock_out_source,
               NULL::text                              AS correction_local_at,
               NULL::text                              AS correction_utc_at,
               NULL::text                              AS author_name,
               NULL::text                              AS author_uuid,
               NULL::text                              AS correction_action,
               NULL::text                              AS reason_code,
               NULL::text                              AS reason_text,
               NULL::text                              AS before_in,
               NULL::text                              AS before_out,
               NULL::integer                           AS before_minutes,
               NULL::text                              AS after_in,
               NULL::text                              AS after_out,
               NULL::integer                           AS after_minutes,
               FALSE                                   AS has_before,
               FALSE                                   AS has_after,
               en.clocked_in_at                        AS sort_instant,
               0                                       AS sort_kind
          FROM entries en
        UNION ALL
        SELECT 'correction'::text,
               sc.employee_code::text,
               sc.last_name::text,
               sc.first_name::text,
               sc.employee_uuid::text,
               sc.site_name::text,
               sc.department_name::text,
               sc.site_timezone::text,
               to_char(sc.work_date, 'YYYY-MM-DD'),
               NULL,
               sc.shift_entry_uuid::text,
               NULL, NULL, NULL, NULL,
               NULL, NULL,
               NULL, NULL, NULL,
               to_char(c.created_at AT TIME ZONE sc.site_timezone, 'YYYY-MM-DD HH24:MI'),
               to_char(c.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
               u.name::text,
               u.uuid::text,
               c.action::text,
               c.reason_code::text,
               c.reason_text::text,
               to_char((c.before ->> 'clocked_in_at')::timestamptz  AT TIME ZONE sc.site_timezone, 'YYYY-MM-DD HH24:MI'),
               to_char((c.before ->> 'clocked_out_at')::timestamptz AT TIME ZONE sc.site_timezone, 'YYYY-MM-DD HH24:MI'),
               (c.before ->> 'worked_minutes')::integer,
               to_char((c.after  ->> 'clocked_in_at')::timestamptz  AT TIME ZONE sc.site_timezone, 'YYYY-MM-DD HH24:MI'),
               to_char((c.after  ->> 'clocked_out_at')::timestamptz AT TIME ZONE sc.site_timezone, 'YYYY-MM-DD HH24:MI'),
               (c.after ->> 'worked_minutes')::integer,
               c.before IS NOT NULL,
               c.after  IS NOT NULL,
               c.created_at,
               1
          FROM shift_corrections c
          JOIN scope sc ON sc.id = c.shift_entry_id
          JOIN users u  ON u.id  = c.performed_by_user_id
         ORDER BY employee_code, work_date, sort_kind, sort_instant, entry_number
        SQL;

    public function __construct(private ConnectionInterface $connection) {}

    public function records(LegalExportPeriod $period, LegalExportScope $scope): iterable
    {
        $cursor = $this->declareCursor($period, $scope);

        try {
            while (true) {
                /** @var list<object> $rows */
                $rows = $this->connection->select('FETCH FORWARD '.self::FETCH_SIZE.' FROM '.$cursor);

                if ($rows === []) {
                    return;
                }

                foreach ($rows as $row) {
                    yield $this->toRecord(Row::of($row));
                }
            }
        } finally {
            $this->connection->statement('CLOSE '.$cursor);
        }
    }

    /**
     * Abre el cursor y devuelve su nombre.
     *
     * El nombre lleva un sufijo aleatorio porque un cursor es un objeto **de la
     * sesion**: dos exportaciones que compartieran conexion —el trabajador de
     * colas de la tarea 3.9 las hara asincronas— chocarian por nombre. El sufijo
     * es hexadecimal, asi que nunca necesita comillas ni escapado.
     */
    private function declareCursor(LegalExportPeriod $period, LegalExportScope $scope): string
    {
        $name = 'kronoqr_legal_export_'.bin2hex(random_bytes(8));

        $this->connection->statement(
            'DECLARE '.$name.' NO SCROLL CURSOR FOR '.self::SQL,
            [$period->from, $period->to, $scope->employeeUuid, $scope->employeeUuid],
        );

        return $name;
    }

    private function toRecord(Row $row): LegalExportRecord
    {
        return LegalExportRecordType::from($row->string('record_type')) === LegalExportRecordType::ShiftEntry
            ? $this->toShiftEntry($row)
            : $this->toCorrection($row);
    }

    private function toSubject(Row $row): ExportedSubject
    {
        return new ExportedSubject(
            employeeCode: $row->string('employee_code'),
            lastName: $row->string('last_name'),
            firstName: $row->string('first_name'),
            employeeUuid: $row->string('employee_uuid'),
            siteName: $row->string('site_name'),
            departmentName: $row->nullableString('department_name'),
            timezone: $row->string('site_timezone'),
            workDate: $row->string('work_date'),
        );
    }

    private function toShiftEntry(Row $row): ExportedShiftEntry
    {
        return new ExportedShiftEntry(
            subject: $this->toSubject($row),
            entryNumber: $row->int('entry_number'),
            shiftEntryUuid: $row->string('shift_entry_uuid'),
            localClockedInAt: $row->string('local_in'),
            localClockedOutAt: $row->nullableString('local_out') ?? '',
            utcClockedInAt: $row->string('utc_in'),
            utcClockedOutAt: $row->nullableString('utc_out') ?? '',
            duration: ExportedDuration::ofNullableMinutes($row->nullableInt('duration_minutes')),
            dayTotal: ExportedDuration::ofMinutes($row->int('day_minutes')),
            status: $row->string('status'),
            clockInSource: $row->string('clock_in_source'),
            clockOutSource: $row->nullableString('clock_out_source') ?? '',
        );
    }

    private function toCorrection(Row $row): ExportedCorrection
    {
        return new ExportedCorrection(
            subject: $this->toSubject($row),
            shiftEntryUuid: $row->string('shift_entry_uuid'),
            localPerformedAt: $row->string('correction_local_at'),
            utcPerformedAt: $row->string('correction_utc_at'),
            authorName: $row->string('author_name'),
            authorUuid: $row->string('author_uuid'),
            action: $row->string('correction_action'),
            reasonCode: $row->string('reason_code'),
            reasonText: $row->nullableString('reason_text') ?? '',
            before: $this->marks(
                $row->bool('has_before'),
                $row->nullableString('before_in'),
                $row->nullableString('before_out'),
                $row->nullableInt('before_minutes'),
            ),
            after: $this->marks(
                $row->bool('has_after'),
                $row->nullableString('after_in'),
                $row->nullableString('after_out'),
                $row->nullableInt('after_minutes'),
            ),
        );
    }

    private function marks(bool $present, ?string $localIn, ?string $localOut, ?int $minutes): ExportedMarks
    {
        return $present
            ? ExportedMarks::of($localIn, $localOut, ExportedDuration::ofNullableMinutes($minutes))
            : ExportedMarks::none();
    }
}
