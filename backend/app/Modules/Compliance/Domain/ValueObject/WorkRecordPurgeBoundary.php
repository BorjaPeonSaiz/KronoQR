<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Hasta donde ha purgado de verdad el registro horario cada centro, y que
 * asientos de purga no se pueden creer (RL-02, ADR-057 §4).
 *
 * ## Por que no basta con leer el corte del ultimo asiento
 *
 * La aplicacion tiene `INSERT` sobre `audit_log` y la cadena de hash no lleva
 * secreto: con su credencial se puede **añadir** un asiento
 * `retention.purge_executed` bien encadenado. Si la conciliacion creyera su
 * `cutoff_date` sin mas, un asiento con corte `9999-12-31` daria por purgado
 * cualquier tramo borrado, para siempre y sin alerta.
 *
 * ## Lo que un asiento de purga tiene que cumplir para contar
 *
 * Los cuatro limites que la purga real no puede saltarse
 * (`ApplyRetention` + `RetentionPolicy::workRecordCutoff()`):
 *
 * - `cutoff_date` es una fecha `YYYY-MM-DD` valida.
 * - `retention_years` es un entero no menor que el **suelo** que admite el
 *   perfil de cumplimiento (lo da un puerto, regla dura 14). El suelo y no el
 *   plazo vigente: un perfil subido despues de una purga legitima haria de
 *   aquella purga un falso positivo.
 * - `cutoff_date` no es posterior a la fecha UTC del propio asiento menos
 *   `retention_years` años, que es exactamente como la calcula la purga.
 * - El asiento no es posterior al momento de la pasada, y dice de que centro es.
 *
 * Un asiento que no los cumple **no explica nada** y ademas sale como
 * discrepancia propia (`purge_out_of_bounds`): nadie escribe uno asi por
 * accidente.
 *
 * Lo que sigue sin poder distinguirse es un asiento falsificado que respeta los
 * cuatro limites —borrar lo que ya se podia purgar—; lo recoge el runbook.
 */
final readonly class WorkRecordPurgeBoundary
{
    /**
     * @param  array<int, string>  $cutoffBySite  Corte admitido mas reciente de cada centro.
     * @param  list<int>  $sealedAuditYears
     * @param  list<WorkRecordDiscrepancy>  $rejected  Un `purge_out_of_bounds` por cada asiento no admisible.
     */
    private function __construct(
        public array $cutoffBySite,
        public array $sealedAuditYears,
        public array $rejected,
    ) {}

    public static function none(): self
    {
        return new self([], [], []);
    }

    public static function assess(WorkRecordAuditContext $context, DateTimeImmutable $runAt, int $minimumRetentionYears): self
    {
        $cutoffBySite = [];
        $rejected = [];

        foreach ($context->purges as $purge) {
            $violations = self::violations($purge, $runAt, max(1, $minimumRetentionYears));

            if ($violations !== [] || $purge->siteId === null || $purge->cutoffDate === null) {
                $rejected[] = new WorkRecordDiscrepancy(
                    null,
                    WorkRecordDiscrepancyKind::PurgeOutOfBounds,
                    $violations,
                    $purge->auditEntryId,
                );

                continue;
            }

            // `YYYY-MM-DD` ya validado: se ordena igual como texto que como fecha.
            $current = $cutoffBySite[$purge->siteId] ?? null;
            $cutoffBySite[$purge->siteId] = $current === null || $purge->cutoffDate > $current
                ? $purge->cutoffDate
                : $current;
        }

        return new self($cutoffBySite, $context->sealedAuditYears, $rejected);
    }

    /**
     * Lo que el asiento incumple, por nombre de campo. Vacia si cuenta.
     *
     * @return list<string>
     */
    public static function violations(AuditedPurge $purge, DateTimeImmutable $runAt, int $minimumRetentionYears): array
    {
        $cutoff = self::calendarDate($purge->cutoffDate);
        $yearsAdmissible = $purge->retentionYears !== null && $purge->retentionYears >= $minimumRetentionYears;

        $checks = [
            'cutoff_date' => $cutoff instanceof DateTimeImmutable
                && (! $yearsAdmissible || $cutoff <= self::latestCutoff($purge->occurredAt, (int) $purge->retentionYears)),
            'retention_years' => $yearsAdmissible,
            'occurred_at' => $purge->occurredAt <= $runAt,
            'site_id' => $purge->siteId !== null,
        ];

        return array_keys(array_filter($checks, static fn (bool $holds): bool => ! $holds));
    }

    /** El corte admitido de un centro, o `null` si nunca purgo. */
    public function cutoffFor(?int $siteId): ?string
    {
        return $siteId === null ? null : ($this->cutoffBySite[$siteId] ?? null);
    }

    /**
     * El corte mas reciente que una purga hecha en ese instante podia usar: la
     * medianoche UTC de ese dia menos los años de conservacion, la misma cuenta
     * que `RetentionPolicy::workRecordCutoff()`.
     */
    private static function latestCutoff(DateTimeImmutable $occurredAt, int $years): DateTimeImmutable
    {
        return $occurredAt->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->sub(new DateInterval('P'.$years.'Y'));
    }

    private static function calendarDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return new DateTimeImmutable($value.'T00:00:00', new DateTimeZone('UTC'));
    }
}
