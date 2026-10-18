<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\SiteTimezoneAuditReader;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezone;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezoneChange;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezoneReview;
use App\Modules\Compliance\Domain\ValueObject\RecordedSiteTimezoneChange;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see SiteTimezoneAuditReader} sobre `sites`, `site_timezone_changes`,
 * `site_timezone_audit_baseline` y `audit_log` (RN-23, bloque 2 de la 2.3.0).
 * Que cuadra lo decide el dominio ({@see AuditedSiteTimezone}).
 *
 * Un centro por instalacion (ADR-040): una fila, unos pocos asientos —uno por
 * cambio del centro y uno por revision— que entran por `audit_log_subject_index`,
 * y una traza que solo crece con cada correccion. Lee `sites` y las dos tablas
 * de la zona por nombre, sin el modelo de `Workforce`, como la conciliacion lee
 * `shift_entries`.
 *
 * **Despues del primer tramo** se decide por el orden de la cadena (ADR-010):
 * hay un asiento `shift_entry.*` con `id` menor. El filtro por `occurred_at`
 * solo acota el recorrido del indice de accion: un dia de margen sobre el reloj
 * de quien escribio el asiento.
 */
final readonly class DatabaseSiteTimezoneAuditReader implements SiteTimezoneAuditReader
{
    public const string TRACE_TABLE = 'site_timezone_changes';

    public const string BASELINE_TABLE = 'site_timezone_audit_baseline';

    public function __construct(private ConnectionInterface $connection) {}

    public function sites(): array
    {
        $audited = $this->auditedChanges();
        $recorded = $this->recordedChanges();
        $reviews = $this->reviews();
        $baseline = $this->baseline();
        $sites = [];
        $rows = $this->connection->select(
            <<<'SQL'
                SELECT s.id, s.timezone, a.id AS audit_id, a.payload::text AS payload
                  FROM sites s
                  LEFT JOIN LATERAL (
                       SELECT l.id, l.payload
                         FROM audit_log l
                        WHERE l.subject_type = 'site'
                          AND l.subject_id = s.id
                          AND l.action IN (?, ?)
                        ORDER BY l.id DESC
                        LIMIT 1
                  ) a ON TRUE
                 ORDER BY s.id
                SQL,
            [AuditAction::SiteCreated->value, AuditAction::SiteUpdated->value],
        );

        foreach ($rows as $raw) {
            $row = Row::of((object) $raw);
            $id = $row->int('id');
            $sites[] = AuditedSiteTimezone::fromEntry(
                $id,
                $row->string('timezone'),
                $row->nullableInt('audit_id'),
                $row->json('payload'),
                $audited[$id] ?? [],
                $recorded[$id] ?? [],
                $reviews[$id] ?? [],
                $baseline,
            );
        }

        return $sites;
    }

    public function sealedAuditYears(): array
    {
        $years = [];

        foreach ($this->connection->select('SELECT partition_year FROM '.AuditLogSchema::ANCHORS_TABLE.' ORDER BY partition_year') as $row) {
            $years[] = Row::of((object) $row)->int('partition_year');
        }

        return $years;
    }

    /**
     * @return array<int, list<AuditedSiteTimezoneChange>>
     */
    private function auditedChanges(): array
    {
        $audited = [];
        $actions = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            AuditedShiftEntry::ACTIONS,
        ));
        $rows = $this->connection->select(
            \sprintf(
                <<<'SQL'
                    SELECT a.subject_id AS site_id, a.id, a.payload::text AS payload,
                           EXISTS (
                               SELECT 1 FROM audit_log s
                                WHERE s.action IN (%s)
                                  AND s.occurred_at <= a.occurred_at + interval '1 day'
                                  AND s.id < a.id
                           ) AS after_work_record
                      FROM audit_log a
                     WHERE a.subject_type = 'site'
                       AND a.action = ?
                     ORDER BY a.id
                    SQL,
                $actions,
            ),
            [AuditAction::SiteUpdated->value],
        );

        foreach ($rows as $raw) {
            $row = Row::of((object) $raw);
            $audited[$row->int('site_id')][] = AuditedSiteTimezoneChange::fromEntry(
                $row->int('id'),
                $row->json('payload') ?? [],
                $row->bool('after_work_record'),
            );
        }

        return $audited;
    }

    /**
     * @return array<int, list<RecordedSiteTimezoneChange>>
     */
    private function recordedChanges(): array
    {
        $recorded = [];

        foreach ($this->connection->select('SELECT id, site_id, from_tz, to_tz, changed_at, audit_log_id FROM '.self::TRACE_TABLE.' ORDER BY id') as $raw) {
            $row = Row::of((object) $raw);
            $recorded[$row->int('site_id')][] = new RecordedSiteTimezoneChange(
                $row->int('id'),
                $row->string('from_tz'),
                $row->string('to_tz'),
                $row->instant('changed_at'),
                $row->int('audit_log_id'),
            );
        }

        return $recorded;
    }

    /**
     * @return array<int, list<AuditedSiteTimezoneReview>>
     */
    private function reviews(): array
    {
        $reviews = [];
        $rows = $this->connection->select(
            "SELECT a.subject_id AS site_id, a.id, a.payload::text AS payload FROM audit_log a WHERE a.subject_type = 'site' AND a.action = ? ORDER BY a.id",
            [AuditAction::SiteTimezoneDiscrepancyReviewed->value],
        );

        foreach ($rows as $raw) {
            $row = Row::of((object) $raw);
            $reviews[$row->int('site_id')][] = AuditedSiteTimezoneReview::fromEntry($row->int('id'), $row->json('payload') ?? []);
        }

        return $reviews;
    }

    /**
     * La linea base, o `null` si su fila no esta: entonces se juzga todo, que
     * es lo prudente (solo el rol de migracion la puede borrar).
     */
    private function baseline(): ?int
    {
        $row = $this->connection->selectOne('SELECT audit_log_id FROM '.self::BASELINE_TABLE.' WHERE id = 1');

        return $row === null ? null : Row::of((object) $row)->int('audit_log_id');
    }
}
