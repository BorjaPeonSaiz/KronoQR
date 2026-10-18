<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeZone;

/**
 * La zona horaria de un centro frente a su auditoria (RN-23, ADR-056, ADR-057
 * §4; bloque 2 de la 2.3.0).
 *
 * ## Que se compara
 *
 * Desde el bloque 2 de la 2.3.0 la base de datos no deja cambiar la zona con
 * registro horario salvo por la funcion `site_correct_timezone`, que la
 * aplicacion puede ejecutar, que **no escribe asiento** (no puede firmar por
 * nadie: el asiento lo deja la orden de consola) y que **apunta cada cambio** en
 * la traza solo-append `site_timezone_changes`. Sin registro horario, el
 * `UPDATE` es legitimo. Se miran tres cosas, y las tres salen como
 * `site_timezone_differs_from_audit`, con el campo que no cuadra:
 *
 * - `timezone`: la zona de `sites` no es la del ultimo asiento `site.created`
 *   o `site.updated` del centro;
 * - `site_timezone_changes`: una fila de la traza no tiene su `site.updated`
 *   firmado (motivo, recuento y cuenta) con el mismo antes y despues. Es la
 *   huella de la funcion llamada fuera de la orden, tambien de un A→B→A que
 *   deja la zona como estaba;
 * - `correction`: un `site.updated` que cambia la zona despues del primer
 *   tramo sin la firma de una correccion. **Desde la 2.2.1** el panel no puede
 *   escribirlo (`409`), asi que lo escribio otro; antes si podia.
 *
 * **Una sola clase y no tres** porque son el mismo hecho —la historia de la
 * zona del centro no cuadra con su auditoria—, se investigan con la misma
 * seccion del runbook y suenan con la misma alerta; el campo dice cual de las
 * tres comprobaciones fallo.
 *
 * ## Lo que no se puede verificar
 *
 * - Un centro **sin ningun asiento `site.*`**: uno creado antes de la tarea
 *   5.5, o cuyo asiento se fue con la particion de su año (ADR-027). No hay con
 *   que comparar la zona.
 * - Una fila de la traza **de un año cuya particion de `audit_log` ya se
 *   purgo** (el ultimo año sellado o antes): su asiento, si lo hubo, ya no
 *   esta.
 * - Un `site.updated` sin firma **anterior a la linea base**
 *   (`site_timezone_audit_baseline`, el ultimo asiento al aplicar la migracion
 *   de la 2.3.0). Hasta la 2.2.1 el panel cambiaba la zona con fichajes, y una
 *   instalacion que viene de la 2.1.0 o la 2.2.0 tiene esos asientos
 *   legitimos. Por lo mismo, una correccion firmada anterior a la linea base
 *   (las de la 2.2.1, que no dejaban fila) no justifica una fila de la traza.
 *
 * Ninguno se denuncia: se cuentan como **no verificables**
 * ({@see SiteTimezoneAssessment}) para que la comprobacion no se apague en
 * silencio.
 *
 * ## Lo revisado
 *
 * Una discrepancia de la traza o de un asiento sin firma se puede dar por
 * revisada (`site.timezone_discrepancy_reviewed`, {@see AuditedSiteTimezoneReview}):
 * deja de ser pendiente y se cuenta como revisada, que tiene su propia alerta.
 * La de la zona actual no: se resuelve devolviendo la zona por el producto.
 *
 * Lleva el identificador del centro y el del asiento, **nunca la zona**, en lo
 * que sale hacia el log ({@see WorkRecordDiscrepancy}).
 */
final readonly class AuditedSiteTimezone
{
    /**
     * @param  list<AuditedSiteTimezoneChange>  $auditedChanges  Los `site.updated` del centro, en el orden de la cadena.
     * @param  list<RecordedSiteTimezoneChange>  $recordedChanges  Las filas de `site_timezone_changes` del centro.
     * @param  list<AuditedSiteTimezoneReview>  $reviews  Los `site.timezone_discrepancy_reviewed` del centro.
     */
    public function __construct(
        public int $siteId,
        /** `sites.timezone`, tal cual. */
        public string $recordedTimezone,
        /** El `id` del ultimo asiento `site.created` o `site.updated` del centro, o `null` si no hay. */
        public ?int $auditEntryId,
        /** `new_value.timezone` de ese asiento, o `null` si no lo trae. */
        public ?string $auditedTimezone,
        public array $auditedChanges = [],
        public array $recordedChanges = [],
        public array $reviews = [],
        /**
         * La linea base (`site_timezone_audit_baseline`): el ultimo asiento al
         * aplicar la migracion de la 2.3.0. Lo que hay hasta ella se escribio
         * con las reglas de antes. `null` si no hay linea base: se juzga todo.
         */
        public ?int $auditBaseline = null,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload  El del ultimo asiento, o `null` si no hay
     * @param  list<AuditedSiteTimezoneChange>  $auditedChanges
     * @param  list<RecordedSiteTimezoneChange>  $recordedChanges
     * @param  list<AuditedSiteTimezoneReview>  $reviews
     */
    public static function fromEntry(
        int $siteId,
        string $recordedTimezone,
        ?int $auditEntryId,
        ?array $payload,
        array $auditedChanges = [],
        array $recordedChanges = [],
        array $reviews = [],
        ?int $auditBaseline = null,
    ): self {
        $newValue = $payload['new_value'] ?? null;
        $timezone = \is_array($newValue) ? ($newValue['timezone'] ?? null) : null;

        return new self(
            $siteId,
            $recordedTimezone,
            $auditEntryId,
            \is_string($timezone) ? $timezone : null,
            $auditedChanges,
            $recordedChanges,
            $reviews,
            $auditBaseline,
        );
    }

    /**
     * La discrepancia de la zona **actual**, si no es la de su ultimo asiento.
     * Un asiento que no dice zona tampoco la justifica; un centro sin asiento
     * no se denuncia (es no verificable, {@see self::assess()}).
     */
    public function discrepancy(): ?WorkRecordDiscrepancy
    {
        if ($this->auditEntryId === null || $this->auditedTimezone === $this->recordedTimezone) {
            return null;
        }

        return $this->discrepancyOf('timezone', $this->auditEntryId);
    }

    /**
     * Las tres comprobaciones, lo revisado y lo que queda sin verificar.
     *
     * @param  list<int>  $sealedAuditYears  Años de `audit_log` purgados con su ancla (ADR-027).
     */
    public function assess(array $sealedAuditYears): SiteTimezoneAssessment
    {
        $current = $this->discrepancy();
        [$unsigned, $historical, $reviewedEntries] = $this->unsignedChanges();
        [$unmatched, $unverifiableRows, $reviewedChanges] = $this->unmatchedChanges($sealedAuditYears);

        return new SiteTimezoneAssessment(
            [
                ...($current instanceof WorkRecordDiscrepancy ? [$current] : []),
                ...$unsigned,
                ...$unmatched,
            ],
            $this->auditEntryId === null,
            $historical + $unverifiableRows,
            $reviewedChanges,
            $reviewedEntries,
        );
    }

    /**
     * Las discrepancias que se pueden dar por revisadas (las de la traza y las
     * de un asiento sin firma), sin las ya revisadas. Lo que lee
     * `compliance:reconcile-work-record --acknowledge-site-change` para saber
     * si lo que se pide revisar sigue pendiente.
     *
     * @param  list<int>  $sealedAuditYears
     * @return list<WorkRecordDiscrepancy>
     */
    public function reviewable(array $sealedAuditYears): array
    {
        return array_values(array_filter(
            $this->assess($sealedAuditYears)->discrepancies,
            static fn (WorkRecordDiscrepancy $discrepancy): bool => $discrepancy->fields !== ['timezone'],
        ));
    }

    /** Si ya hay una revision que cuente para `$target`. */
    public function isReviewed(SiteTimezoneReviewTarget $target): bool
    {
        if (! $target->isChange) {
            return $this->reviewedEntry($target->id);
        }

        foreach ($this->recordedChanges as $row) {
            if ($row->id === $target->id) {
                return $this->reviewedChange($row);
            }
        }

        return false;
    }

    /**
     * Los `site.updated` que cambian la zona con registro horario sin la firma
     * de la correccion. Desde la 2.2.1 el panel no los puede escribir; los
     * anteriores a la linea base son de versiones en las que si podia (RN-23
     * llego en la 2.2.1) y se cuentan como no verificables.
     *
     * @return array{0: list<WorkRecordDiscrepancy>, 1: int, 2: int}
     */
    private function unsignedChanges(): array
    {
        $found = [];
        $historical = 0;
        $reviewed = 0;

        foreach ($this->auditedChanges as $change) {
            if (! $change->changesTimezone() || ! $change->afterWorkRecord || $change->signed) {
                continue;
            }

            if ($this->beforeBaseline($change->auditEntryId)) {
                $historical++;
            } elseif ($this->reviewedEntry($change->auditEntryId)) {
                $reviewed++;
            } else {
                $found[] = $this->discrepancyOf('correction', $change->auditEntryId);
            }
        }

        return [$found, $historical, $reviewed];
    }

    /**
     * Cada fila de la traza, con **su** asiento firmado: el que la nombra por
     * `site_timezone_change_id`, con el mismo antes y despues, posterior a la
     * linea base. Por identidad: una correccion de la 2.2.1 (sin fila, anterior
     * a la linea base) no justifica una fila de hoy, una fila revisada no se
     * come el asiento de otra, y una correccion legitima no tapa la fila del
     * ataque. Las filas de un año cuya auditoria ya se purgo no se pueden
     * cotejar y se cuentan aparte.
     *
     * @param  list<int>  $sealedAuditYears
     * @return array{0: list<WorkRecordDiscrepancy>, 1: int, 2: int}
     */
    private function unmatchedChanges(array $sealedAuditYears): array
    {
        $lastSealedYear = $sealedAuditYears === [] ? null : max($sealedAuditYears);
        $found = [];
        $unverifiable = 0;
        $reviewed = 0;

        foreach ($this->recordedChanges as $row) {
            // Las particiones de `audit_log` son por año UTC (ADR-027).
            if ($lastSealedYear !== null && (int) $row->changedAt->setTimezone(new DateTimeZone('UTC'))->format('Y') <= $lastSealedYear) {
                $unverifiable++;
            } elseif ($this->recorded($row)) {
                continue;
            } elseif ($this->reviewedChange($row)) {
                $reviewed++;
            } else {
                $found[] = $this->discrepancyOf('site_timezone_changes', null, $row->id);
            }
        }

        return [$found, $unverifiable, $reviewed];
    }

    /** Si un asiento firmado, posterior a la linea base, asienta esa fila. */
    private function recorded(RecordedSiteTimezoneChange $row): bool
    {
        foreach ($this->auditedChanges as $change) {
            if (! $this->beforeBaseline($change->auditEntryId) && $change->records($row)) {
                return true;
            }
        }

        return false;
    }

    private function beforeBaseline(int $auditEntryId): bool
    {
        return $this->auditBaseline !== null && $auditEntryId <= $this->auditBaseline;
    }

    private function reviewedEntry(int $auditEntryId): bool
    {
        foreach ($this->reviews as $review) {
            if ($review->reviewsEntry($auditEntryId)) {
                return true;
            }
        }

        return false;
    }

    private function reviewedChange(RecordedSiteTimezoneChange $row): bool
    {
        foreach ($this->reviews as $review) {
            if ($review->reviewsChange($row)) {
                return true;
            }
        }

        return false;
    }

    private function discrepancyOf(string $field, ?int $auditEntryId, ?int $changeId = null): WorkRecordDiscrepancy
    {
        return new WorkRecordDiscrepancy(
            shiftEntryUuid: null,
            kind: WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit,
            fields: [$field],
            auditEntryId: $auditEntryId,
            siteId: $this->siteId,
            siteTimezoneChangeId: $changeId,
        );
    }
}
