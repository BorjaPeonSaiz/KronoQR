<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\WorkRecordAuditSource;
use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * La consulta que empareja cada tramo de `shift_entries` con su ultimo asiento
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
 * 1. **El ultimo asiento de cada tramo** (`latest`). Cada asiento habla de un
 *    tramo por `shift_entry_uuid`, y los de una correccion que sustituye una
 *    version hablan ademas de la anterior por `superseded_shift_entry_uuid`. Se
 *    despliegan los dos papeles con un `UNION ALL` y `DISTINCT ON` se queda con
 *    el de mayor `id`, que es el ultimo escrito: la cadena serializa las
 *    escrituras (ADR-010), asi que el orden de `id` es el orden de los hechos.
 * 2. **Los candidatos**: los tramos de la ventana mas los que aparecen en algun
 *    asiento de la ventana. La union es lo que hace que salga tanto el tramo sin
 *    asiento como el asiento sin tramo.
 * 3. **Cada candidato con su fila, su asiento y sus correcciones.** El asiento
 *    se relee por su clave primaria `(id, occurred_at)`, que poda la particion.
 *
 * El identificador del payload se convierte a `uuid` solo si tiene forma de
 * `uuid`: un payload esta protegido por la cadena, pero un `CAST` que fallara
 * tumbaria la pasada entera y la alerta de silencio taparia la de verdad.
 *
 * ## Por indice, y medido
 *
 * La pasada diaria entra en `audit_log` por `audit_log_action_index` (accion +
 * instante, el que hereda cada particion) y en `shift_entries` por
 * `shift_entries_work_date_index`; ninguna de las dos necesita indice nuevo. La
 * completa lee las dos tablas enteras, que es lo que tiene que hacer: medida con
 * 876 000 tramos y 2,6 millones de asientos (300 personas, cuatro años), la
 * consulta tarda 254 ms en la diaria y 21 s en la completa en el entorno de
 * desarrollo, con `work_mem` de 4 MB; el comando entero, 2,5 s y 1 min 42 s,
 * dentro de 128 MB de memoria.
 *
 * ## Cursor de servidor, en una instantanea `REPEATABLE READ`
 *
 * `DECLARE ... CURSOR` y `FETCH FORWARD` por lotes: el driver trae al cliente el
 * resultado entero de un `SELECT` normal. Y la transaccion se abre **aqui** con
 * `REPEATABLE READ, READ ONLY`, porque el corte de la ultima purga y los años
 * sellados se leen con sentencias aparte y tienen que ver el mismo instante que
 * el cursor: una purga confirmada entre las dos lecturas convertiria lo recien
 * purgado en un borrado. Dentro de una transaccion ya abierta —la suite— el
 * nivel lo fija la de fuera.
 */
final readonly class DatabaseWorkRecordAuditSource implements WorkRecordAuditSource
{
    /**
     * Las dos ramas del ultimo asiento. `%s` es el filtro de instante de la
     * ventana diaria, o nada en la completa: un fragmento fijo de esta clase,
     * nunca un valor.
     */
    private const string LATEST = <<<'SQL'
        audited AS (
            SELECT a.id,
                   a.occurred_at,
                   CASE WHEN a.payload ->> 'shift_entry_uuid' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                        THEN CAST(a.payload ->> 'shift_entry_uuid' AS uuid) END AS entry_uuid,
                   FALSE AS as_superseded
              FROM audit_log a
             WHERE a.action IN ('shift_entry.created', 'shift_entry.modified', 'shift_entry.closed', 'shift_entry.voided')
               %1$s
            UNION ALL
            SELECT a.id,
                   a.occurred_at,
                   CASE WHEN a.payload ->> 'superseded_shift_entry_uuid' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                        THEN CAST(a.payload ->> 'superseded_shift_entry_uuid' AS uuid) END,
                   TRUE
              FROM audit_log a
             WHERE a.action IN ('shift_entry.modified', 'shift_entry.closed')
               AND a.payload ->> 'superseded_shift_entry_uuid' IS NOT NULL
               %1$s
        ),
        latest AS (
            SELECT DISTINCT ON (entry_uuid) entry_uuid, id, occurred_at, as_superseded
              FROM audited
             WHERE entry_uuid IS NOT NULL
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
               rep.uuid::text                    AS superseded_by_uuid,
               (SELECT string_agg(sc.action, ',' ORDER BY sc.id)
                  FROM shift_corrections sc
                 WHERE sc.shift_entry_id = se.id) AS correction_actions,
               (SELECT string_agg(sc.action, ',' ORDER BY sc.id)
                  FROM shift_corrections sc
                 WHERE sc.shift_entry_id = se.superseded_by_id) AS replacement_correction_actions,
               l.id                              AS audit_id,
               l.as_superseded                   AS as_superseded,
               a.action                          AS audit_action,
               a.payload::text                   AS audit_payload
          FROM candidates c
          LEFT JOIN shift_entries se  ON se.uuid = c.entry_uuid
          LEFT JOIN employees     e   ON e.id = se.employee_id
          LEFT JOIN shift_entries rep ON rep.id = se.superseded_by_id
          LEFT JOIN latest        l   ON l.entry_uuid = c.entry_uuid
          LEFT JOIN audit_log     a   ON a.id = l.id AND a.occurred_at = l.occurred_at
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        /** Filas por `FETCH`. Solo cambia cuantas veces se habla con la base, no el resultado. */
        private int $fetchSize = 500,
    ) {}

    public function pairs(WorkRecordReconciliationWindow $window, int $chunkSize): iterable
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

            $purgedThrough = $this->purgedThrough();
            $sealedAuditYears = $this->sealedAuditYears();
            $cursor = $this->declareCursor($window);
            $size = max(1, $chunkSize > 0 ? $chunkSize : $this->fetchSize);

            while (true) {
                /** @var list<object> $rows */
                $rows = $this->connection->select('FETCH FORWARD '.$size.' FROM '.$cursor);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    yield $this->toPair(Row::of($row), $purgedThrough, $sealedAuditYears);
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
     * El corte de la ultima purga del registro que dejo su asiento. Se lee del
     * asiento y no del perfil de cumplimiento: un plazo cambiado despues de
     * purgar no puede convertir lo purgado en borrado, y un borrado sin asiento
     * de purga no se puede disfrazar de purga.
     */
    private function purgedThrough(): ?string
    {
        $row = $this->connection->selectOne(
            <<<'SQL'
                SELECT max(a.payload ->> 'cutoff_date') AS cutoff
                  FROM audit_log a
                 WHERE a.action = 'retention.purge_executed'
                   AND a.payload ->> 'scope' = ?
                SQL,
            [RetentionScope::WorkRecords->value],
        );

        return \is_object($row) ? Row::of($row)->nullableString('cutoff') : null;
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

        if ($recent) {
            $since = $window->auditSince->format(DateTimeInterface::ATOM);
            $sql = 'WITH '.\sprintf(self::LATEST, 'AND a.occurred_at >= CAST(? AS timestamptz)')
                .', candidates AS (SELECT entry_uuid FROM latest UNION SELECT se.uuid FROM shift_entries se WHERE se.work_date >= CAST(? AS date)) '
                .self::PAIRS;
            $bindings = [$since, $since, $window->fromWorkDate];
        } else {
            $sql = 'WITH '.\sprintf(self::LATEST, '')
                .', candidates AS (SELECT entry_uuid FROM latest UNION SELECT se.uuid FROM shift_entries se) '
                .self::PAIRS;
            $bindings = [];
        }

        $this->connection->statement('DECLARE '.$name.' NO SCROLL CURSOR FOR '.$sql, $bindings);

        return $name;
    }

    /**
     * @param  list<int>  $sealedAuditYears
     */
    private function toPair(Row $row, ?string $purgedThrough, array $sealedAuditYears): WorkRecordPair
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
            correctionActions: $this->actions($row->nullableString('correction_actions')),
            replacementCorrectionActions: $this->actions($row->nullableString('replacement_correction_actions')),
        );

        $audited = $row->nullableInt('audit_id') === null ? null : AuditedShiftEntry::fromLatestEntry(
            $row->int('audit_id'),
            $row->string('audit_action'),
            $row->json('audit_payload') ?? [],
            $row->bool('as_superseded'),
        );

        return new WorkRecordPair($uuid, $recorded, $audited, $purgedThrough, $sealedAuditYears);
    }

    /**
     * @return list<string>
     */
    private function actions(?string $joined): array
    {
        return $joined === null || $joined === '' ? [] : explode(',', $joined);
    }
}
