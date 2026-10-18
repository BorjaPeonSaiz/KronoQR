<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Command\ReviewSiteTimezoneDiscrepancyCommand;
use App\Modules\Compliance\Application\Port\SiteTimezoneAuditReader;
use App\Modules\Compliance\Domain\Exception\SiteTimezoneReviewRejected;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezone;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\SiteTimezoneReviewTarget;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Domain\ValueObject\SignOffReason;
use App\Modules\Shared\Domain\ValueObject\SignOffReasonViolation;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Da por revisada una discrepancia de la zona del centro que la conciliacion
 * no puede dejar de contar (RN-23, ADR-056; bloque 2 de la 2.3.0): una fila de
 * la traza `site_timezone_changes` sin su asiento firmado, o un `site.updated`
 * que cambio la zona sin firma.
 *
 * **Mismo patron que la revision de una purga** ({@see ReviewOutOfBoundsPurge},
 * ADR-057 enmienda del 10-10-2026 §6):
 *
 * - **no borra ni corrige nada**: la traza es solo-append y la cadena tambien;
 *   se añade un asiento `site.timezone_discrepancy_reviewed` que la nombra, con
 *   la cuenta que responde y el motivo;
 * - la conciliacion deja de contarla como pendiente y la publica como
 *   revisada, que dispara su propia alerta (`DiscrepanciaDeZonaRevisada`): una
 *   revision, legitima o no, nunca pasa en silencio;
 * - la firma una cuenta **activa con rol admin o rrhh**
 *   ({@see ReviewOutOfBoundsPurge::RESPONSIBLE_ROLES}), comprobada fuera del
 *   candado y otra vez dentro;
 * - que la discrepancia siga pendiente y no este ya revisada se comprueba
 *   **dentro** de `SerializedLedgerWrite::withChainLock`, para que dos consolas
 *   a la vez no escriban dos revisiones de lo mismo.
 *
 * La de la zona **actual** (`timezone`) no se revisa: se resuelve devolviendo
 * la zona por el producto, que deja el asiento que falta.
 *
 * **El motivo nunca sale de aqui hacia un log** (regla dura 21).
 */
final readonly class ReviewSiteTimezoneDiscrepancy
{
    public function __construct(
        private SiteTimezoneAuditReader $reader,
        private ResponsibleAccounts $accounts,
        private SerializedLedgerWrite $ledgerWrite,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * El centro de la discrepancia, sin escribir nada. Falla igual que
     * `handle()` si no se puede revisar.
     *
     * @throws SiteTimezoneReviewRejected
     */
    public function preview(SiteTimezoneReviewTarget $target, ?int $responsibleUserId = null): int
    {
        if ($responsibleUserId !== null) {
            $this->assertResponsible($responsibleUserId);
        }

        return $this->pendingSite($target)->siteId;
    }

    /**
     * El id del asiento de revision escrito.
     *
     * @throws SiteTimezoneReviewRejected
     */
    public function handle(ReviewSiteTimezoneDiscrepancyCommand $command): int
    {
        $span = SpanScope::start(
            'kronoqr.compliance',
            'compliance.review_site_timezone_discrepancy',
            SpanKind::KIND_INTERNAL,
            ['target' => $command->target->label()],
        );

        try {
            $reviewId = $this->review($command);
        } catch (Throwable $failure) {
            $span->end(['outcome' => $failure instanceof SiteTimezoneReviewRejected ? 'rejected' : 'error']);

            throw $failure;
        }

        $span->end(['outcome' => 'reviewed', 'review_entry_id' => $reviewId]);

        return $reviewId;
    }

    /**
     * @throws SiteTimezoneReviewRejected
     */
    private function review(ReviewSiteTimezoneDiscrepancyCommand $command): int
    {
        // Fuera del candado: no dependen de la cadena.
        $reason = SignOffReason::fromText(
            $command->reason,
            static fn (SignOffReasonViolation $violation, int $limit): SiteTimezoneReviewRejected => match ($violation) {
                SignOffReasonViolation::TooShort => SiteTimezoneReviewRejected::reasonTooShort($limit),
                SignOffReasonViolation::TooLong => SiteTimezoneReviewRejected::reasonTooLong($limit),
            },
        )->text;

        $this->assertResponsible($command->responsibleUserId);

        return $this->ledgerWrite->withChainLock(function () use ($command, $reason): int {
            $this->assertResponsible($command->responsibleUserId);

            $site = $this->pendingSite($command->target);
            $target = $command->target;

            $entry = $this->audit->handle(new RecordAuditEntryCommand(
                actor: AuditActor::user($command->responsibleUserId),
                action: AuditAction::SiteTimezoneDiscrepancyReviewed,
                subject: AuditSubject::of('site', $site->siteId),
                payload: AuditPayload::of([
                    ...($target->isChange
                        ? ['reviewed_site_timezone_change_id' => $target->id]
                        : ['reviewed_audit_entry_id' => $target->id, 'reviewed_action' => AuditAction::SiteUpdated->value]),
                    'site_id' => $site->siteId,
                    'reason' => $reason,
                    'responsible_user_id' => $command->responsibleUserId,
                    'channel' => 'console',
                ]),
            ));

            return (int) $entry->id;
        });
    }

    /**
     * @throws SiteTimezoneReviewRejected
     */
    private function assertResponsible(int $userId): void
    {
        if (! $this->accounts->isActiveManagementAccount($userId, ReviewOutOfBoundsPurge::RESPONSIBLE_ROLES)) {
            throw SiteTimezoneReviewRejected::responsibleNotActive($userId);
        }
    }

    /**
     * El centro cuya discrepancia pendiente es `$target`, o por que no se puede
     * revisar: ya revisada, o no es una discrepancia pendiente.
     *
     * @throws SiteTimezoneReviewRejected
     */
    private function pendingSite(SiteTimezoneReviewTarget $target): AuditedSiteTimezone
    {
        $sealed = $this->reader->sealedAuditYears();

        foreach ($this->reader->sites() as $site) {
            foreach ($site->reviews as $review) {
                if ($target->reviewedBy($review)) {
                    throw SiteTimezoneReviewRejected::alreadyReviewed($target->label());
                }
            }

            foreach ($site->reviewable($sealed) as $discrepancy) {
                if ($target->matches($discrepancy)) {
                    return $site;
                }
            }
        }

        throw SiteTimezoneReviewRejected::notPending($target->label());
    }
}
