<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\WorkRecordAuditSource;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditTrailEntry;
use App\Modules\Compliance\Domain\ValueObject\RecordedCorrection;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordAuditContext;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPurgeBoundary;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * La consulta que empareja cada tramo de `shift_entries` con sus asientos
 * `shift_entry.*` de `audit_log` (ADR-057 §4).
 *
 * ## Lee `shift_entries` y `shift_corrections` sin tocar `Attendance`
 *
 * Igual que la exportacion legal ({@see DatabaseLegalExportSource}): este modulo
 * no puede importar `Attendance` (doc 02 §1.6), y lo que necesita no es el
 * agregado sino lo que **hay en la fila**. SQL y no Eloquent.
 *
 * ## La consulta, en tres pasos
 *
 * 1. **Tres asientos por tramo.** Cada asiento habla de un tramo por
 *    `shift_entry_uuid`, y los de una correccion que sustituye una version
 *    hablan ademas de la anterior por `superseded_shift_entry_uuid`. Se
 *    despliegan los dos papeles con un `UNION ALL` y `DISTINCT ON` saca el
 *    **ultimo** (por los dos papeles: marcas, estado, version), el **primero**
 *    en el que el tramo es el sujeto (origen de la entrada) y el **ultimo que no
 *    es una anulacion** en el que lo es (origen de la salida). El orden de `id`
 *    es el orden de los hechos: la cadena serializa las escrituras (ADR-010).
 * 2. **Los candidatos**: los tramos de la ventana mas los que aparecen en algun
 *    asiento de la ventana. La union es lo que hace que salga tanto el tramo sin
 *    asiento como el asiento sin tramo.
 * 3. **Cada candidato con su fila, sus asientos y sus correcciones.** Los
 *    asientos se releen por su clave primaria `(id, occurred_at)`, que poda la
 *    particion.
 *
 * Las acciones del filtro salen de {@see AuditedShiftEntry::ACTIONS} y
 * {@see AuditedShiftEntry::SUPERSEDING_ACTIONS}, que se componen con
 * {@see AuditAction}: un nombre nuevo del catalogo no puede quedarse fuera de la
 * consulta por un literal olvidado.
 *
 * El identificador del payload se convierte a `uuid` solo si tiene forma de
 * `uuid`: un `CAST` que fallara tumbaria la pasada entera y la alerta de
 * silencio taparia la de verdad.
 *
 * ## Por indice, y medido
 *
 * La pasada diaria entra en `audit_log` por `audit_log_action_index` (accion +
 * instante, el que hereda cada particion) y en `shift_entries` por
 * `shift_entries_work_date_index`; ninguna de las dos necesita indice nuevo. La
 * completa lee las dos tablas enteras, que es lo que tiene que hacer. Las
 * cifras medidas estan en `config/compliance.php`
 * (`work_record_reconciliation`).
 *
 * ## Cursor de servidor, en una instantanea `REPEATABLE READ`
 *
 * `DECLARE ... CURSOR` y `FETCH FORWARD` por lotes: el driver trae al cliente el
 * resultado entero de un `SELECT` normal. Y la transaccion se abre **aqui** con
 * `REPEATABLE READ, READ ONLY`, porque los asientos de purga y los años sellados
 * se leen con sentencias aparte y tienen que ver el mismo instante que el
 * cursor: una purga confirmada entre las dos lecturas convertiria lo recien
 * purgado en un borrado. Dentro de una transaccion ya abierta —la suite— el
 * nivel lo fija la de fuera.
 */
final readonly class DatabaseWorkRecordAuditSource implements WorkRecordAuditSource
{
    /**
     * Los asientos de cada tramo. `%1$s` y `%2$s` son las listas de acciones y
     * `%3$s` el filtro de instante de la ventana diaria, o nada en la completa:
     * fragmentos fijos de esta clase, nunca valores.
     */
    private const string TRAIL = <<<'SQL'
        audited AS (
            SELECT a.id,
                   a.occurred_at,
                   a.action,
                   CASE WHEN a.payload ->> 'shift_entry_uuid' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                        THEN CAST(a.payload ->> 'shift_entry_uuid' AS uuid) END AS entry_uuid,
                   FALSE AS as_superseded
              FROM audit_log a
             WHERE a.action IN (%1$s)
               %3$s
            UNION ALL
            SELECT a.id,
                   a.occurred_at,
                   a.action,
                   CASE WHEN a.payload ->> 'superseded_shift_entry_uuid' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                        THEN CAST(a.payload ->> 'superseded_shift_entry_uuid' AS uuid) END,
                   TRUE
              FROM audit_log a
             WHERE a.action IN (%2$s)
               AND a.payload ->> 'superseded_shift_entry_uuid' IS NOT NULL
               %3$s
        ),
        latest AS (
            SELECT DISTINCT ON (entry_uuid) entry_uuid, id, occurred_at, as_superseded
              FROM audited
             WHERE entry_uuid IS NOT NULL
             ORDER BY entry_uuid, id DESC
        ),
        first_subject AS (
            SELECT DISTINCT ON (entry_uuid) entry_uuid, id, occurred_at
              FROM audited
             WHERE entry_uuid IS NOT NULL AND NOT as_superseded
             ORDER BY entry_uuid, id ASC
        ),
        marks_subject AS (
            SELECT DISTINCT ON (entry_uuid) entry_uuid, id, occurred_at
              FROM audited
             WHERE entry_uuid IS NOT NULL AND NOT as_superseded AND action <> %4$s
             ORDER BY entry_uuid, id DESC
        )
        SQL;

    private const string PAIRS = <<<'SQL'
        SELECT c.entry_uuid::text                AS entry_uuid,
               se.id                             AS entry_id,
               e.uuid::text                      AS employee_uuid,
               se.site_id                        AS site_id,
               to_char(se.work_date, 'YYYY-MM-DD') AS work_date,
               to_char(se.clocked_in_at  AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS clocked_in_at,
               to_char(se.clocked_out_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS clocked_out_at,
               se.duration_minutes               AS duration_minutes,
               se.status::text                   AS status,
               se.version                        AS version,
               se.clock_in_source::text          AS clock_in_source,
               se.clock_out_source::text         AS clock_out_source,
               rep.uuid::text                    AS superseded_by_uuid,
               (SELECT json_agg(json_build_object('action', sc.action, 'reason_code', sc.reason_code,
                                                  'performed_by_user_id', sc.performed_by_user_id) ORDER BY sc.id)
                  FROM shift_corrections sc
                 WHERE sc.shift_entry_id = se.id)::text AS corrections,
               (SELECT json_agg(json_build_object('action', sc.action, 'reason_code', sc.reason_code,
                                                  'performed_by_user_id', sc.performed_by_user_id) ORDER BY sc.id)
                  FROM shift_corrections sc
                 WHERE sc.shift_entry_id = se.superseded_by_id)::text AS replacement_corrections,
               l.id                              AS audit_id,
               l.as_superseded                   AS as_superseded,
               a.action                          AS audit_action,
               a.payload::text                   AS audit_payload,
               f.id                              AS first_id,
               fa.action                         AS first_action,
               fa.payload::text                  AS first_payload,
               m.id                              AS marks_id,
               ma.action                         AS marks_action,
               ma.payload::text                  AS marks_payload
          FROM candidates c
          LEFT JOIN shift_entries se  ON se.uuid = c.entry_uuid
          LEFT JOIN employees     e   ON e.id = se.employee_id
          LEFT JOIN shift_entries rep ON rep.id = se.superseded_by_id
          LEFT JOIN latest        l   ON l.entry_uuid = c.entry_uuid
          LEFT JOIN audit_log     a   ON a.id = l.id AND a.occurred_at = l.occurred_at
          LEFT JOIN first_subject f   ON f.entry_uuid = c.entry_uuid
          LEFT JOIN audit_log     fa  ON fa.id = f.id AND fa.occurred_at = f.occurred_at
          LEFT JOIN marks_subject m   ON m.entry_uuid = c.entry_uuid
          LEFT JOIN audit_log     ma  ON ma.id = m.id AND ma.occurred_at = m.occurred_at
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        /** Filas por `FETCH`. Solo cambia cuantas veces se habla con la base, no el resultado. */
        private int $fetchSize = 500,
    ) {}

    public function read(WorkRecordReconciliationWindow $window, int $chunkSize): iterable
    {
        $outermost = $this->connection->transactionLevel() === 0;
        $this->connection->beginTransaction();
        $finished = false;

        try {
            if ($outermost) {
                // Primera sentencia de la transaccion: PostgreSQL no admite
                // `SET TRANSACTION` despues de ninguna consulta (25001).
                $this->connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            yield new WorkRecordAuditContext($this->purges(), $this->sealedAuditYears());

            $cursor = $this->declareCursor($window);
            $size = max(1, $chunkSize > 0 ? $chunkSize : $this->fetchSize);

            while (true) {
                /** @var list<object> $rows */
                $rows = $this->connection->select('FETCH FORWARD '.$size.' FROM '.$cursor);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    yield $this->toPair(Row::of($row));
                }
            }

            $this->connection->statement('CLOSE '.$cursor);
            $this->connection->commit();
            $finished = true;
        } finally {
            // Una pasada que se corta a medias —una excepcion, o quien itera
            // deja de hacerlo— no deja la transaccion abierta en la conexion.
            if (! $finished) {
                $this->connection->rollBack();
            }
        }
    }

    /**
     * Todos los asientos de purga del registro, sin filtrar. Cuales se creen lo
     * decide el dominio ({@see WorkRecordPurgeBoundary}):
     * un `max()` aqui seria creerse el primer `9999-12-31` que alguien añada.
     *
     * Son pocos —uno por purga ejecutada, una o dos al año— y entran por
     * `audit_log_action_index`.
     *
     * @return list<AuditedPurge>
     */
    private function purges(): array
    {
        $purges = [];
        $rows = $this->connection->select(
            <<<'SQL'
                SELECT a.id, a.occurred_at, a.payload::text AS payload
                  FROM audit_log a
                 WHERE a.action = ?
                   AND a.payload ->> 'scope' = ?
                 ORDER BY a.id
                SQL,
            [AuditAction::RetentionPurgeExecuted->value, RetentionScope::WorkRecords->value],
        );

        foreach ($rows as $raw) {
            $row = Row::of((object) $raw);
            $purges[] = AuditedPurge::fromEntry($row->int('id'), $row->instant('occurred_at'), $row->json('payload') ?? []);
        }

        return $purges;
    }

    /**
     * @return list<int>
     */
    private function sealedAuditYears(): array
    {
        $years = [];

        foreach ($this->connection->select('SELECT partition_year FROM '.AuditLogSchema::ANCHORS_TABLE.' ORDER BY partition_year') as $row) {
            $years[] = Row::of((object) $row)->int('partition_year');
        }

        return $years;
    }

    /**
     * Abre el cursor y devuelve su nombre, con sufijo aleatorio porque un cursor
     * es un objeto de la sesion. Hexadecimal: nunca necesita comillas.
     */
    private function declareCursor(WorkRecordReconciliationWindow $window): string
    {
        $name = 'kronoqr_work_record_reconciliation_'.bin2hex(random_bytes(8));
        $recent = $window->scope === WorkRecordReconciliationScope::Recent
            && $window->auditSince instanceof DateTimeInterface
            && $window->fromWorkDate !== null;

        $trail = static fn (string $since): string => \sprintf(
            self::TRAIL,
            self::quoted(AuditedShiftEntry::ACTIONS),
            self::quoted(AuditedShiftEntry::SUPERSEDING_ACTIONS),
            $since,
            self::quoted([AuditAction::ShiftEntryVoided->value]),
        );

        if ($recent) {
            $since = $window->auditSince->format(DateTimeInterface::ATOM);
            $sql = 'WITH '.$trail('AND a.occurred_at >= CAST(? AS timestamptz)')
                .', candidates AS (SELECT entry_uuid FROM latest UNION SELECT se.uuid FROM shift_entries se WHERE se.work_date >= CAST(? AS date)) '
                .self::PAIRS;
            $bindings = [$since, $since, $window->fromWorkDate];
        } else {
            $sql = 'WITH '.$trail('')
                .', candidates AS (SELECT entry_uuid FROM latest UNION SELECT se.uuid FROM shift_entries se) '
                .self::PAIRS;
            $bindings = [];
        }

        $this->connection->statement('DECLARE '.$name.' NO SCROLL CURSOR FOR '.$sql, $bindings);

        return $name;
    }

    /**
     * Una lista de acciones del catalogo como literales SQL. Son valores de
     * {@see AuditAction}, nunca entrada de nadie, pero se escapan igual.
     *
     * @param  list<string>  $values
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }

    private function toPair(Row $row): WorkRecordPair
    {
        $uuid = $row->string('entry_uuid');

        $recorded = $row->nullableInt('entry_id') === null ? null : new RecordedShiftEntry(
            uuid: $uuid,
            employeeUuid: $row->string('employee_uuid'),
            siteId: $row->int('site_id'),
            workDate: $row->string('work_date'),
            clockedInAt: $row->string('clocked_in_at'),
            clockedOutAt: $row->nullableString('clocked_out_at'),
            durationMinutes: $row->nullableInt('duration_minutes'),
            status: $row->string('status'),
            version: $row->int('version'),
            supersededByUuid: $row->nullableString('superseded_by_uuid'),
            clockInSource: $row->string('clock_in_source'),
            clockOutSource: $row->nullableString('clock_out_source'),
            corrections: $this->corrections($row->json('corrections')),
            replacementCorrections: $this->corrections($row->json('replacement_corrections')),
        );

        $latest = $this->trailEntry($row, 'audit_id', 'audit_action', 'audit_payload');
        $audited = $latest instanceof AuditTrailEntry
            ? AuditedShiftEntry::fromTrail(
                $latest,
                $row->bool('as_superseded'),
                $this->trailEntry($row, 'first_id', 'first_action', 'first_payload'),
                $this->trailEntry($row, 'marks_id', 'marks_action', 'marks_payload'),
            )
            : null;

        return new WorkRecordPair($uuid, $recorded, $audited);
    }

    private function trailEntry(Row $row, string $id, string $action, string $payload): ?AuditTrailEntry
    {
        return $row->nullableInt($id) === null
            ? null
            : new AuditTrailEntry($row->int($id), $row->string($action), $row->json($payload) ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $rows
     * @return list<RecordedCorrection>
     */
    private function corrections(?array $rows): array
    {
        $corrections = [];

        foreach ($rows ?? [] as $correction) {
            if (! \is_array($correction)) {
                continue;
            }

            $action = $correction['action'] ?? null;
            $reason = $correction['reason_code'] ?? null;
            $author = $correction['performed_by_user_id'] ?? null;

            if (\is_string($action) && \is_string($reason) && \is_int($author)) {
                $corrections[] = new RecordedCorrection($action, $reason, $author);
            }
        }

        return $corrections;
    }
}
