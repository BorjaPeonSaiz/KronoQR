<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Command\ReviewOutOfBoundsPurgeCommand;
use App\Modules\Compliance\Application\Port\PurgeReviewLedger;
use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\PurgeFloors;
use App\Modules\Compliance\Domain\ValueObject\PurgeReviewReason;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPurgeBoundary;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\RetentionYearsFloor;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Da por revisado un asiento de purga del registro horario que la conciliacion
 * juzga no admisible (`purge_out_of_bounds`, RL-04, RS-07, ADR-057 enmienda del
 * 10-10-2026 §6).
 *
 * **No borra ni corrige nada.** La purga sigue en `audit_log` (regla dura 6); lo
 * que se añade es un asiento `retention.purge_reviewed` que la nombra, con autor,
 * motivo y lo que explica. Desde entonces la conciliacion deja de contarla como
 * pendiente y la publica como revisada, que es lo que dispara su propia alerta:
 * una revision, legitima o no, nunca pasa en silencio.
 *
 * **El orden de los pasos.** El motivo y el responsable —cuenta activa con rol
 * admin o rrhh— se comprueban fuera del candado, y el responsable otra vez
 * dentro, antes del asiento. Todo lo demas —que el asiento sea una
 * purga, que no sea admisible y que no este ya revisado— se comprueba **dentro**
 * de `SerializedLedgerWrite::withChainLock`, en el orden de candados de siempre,
 * para que dos consolas a la vez no escriban dos revisiones de la misma purga.
 *
 * **El motivo nunca sale de aqui hacia un log** (regla dura 21): es texto libre
 * y va solo al asiento.
 */
final readonly class ReviewOutOfBoundsPurge
{
    /**
     * Quien puede firmar una revision: administracion o RRHH, como la correccion
     * de la zona del centro. Decision del propietario del 16-10-2026, que cambia
     * la D7 de la 2.2.1 (antes valia cualquier cuenta de gestion activa).
     *
     * @var list<UserRole>
     */
    public const array RESPONSIBLE_ROLES = [UserRole::ADMIN, UserRole::RRHH];

    public function __construct(
        private PurgeReviewLedger $ledger,
        private ResponsibleAccounts $accounts,
        private RetentionYearsFloor $floors,
        private InstallationSiteProvider $sites,
        private Clock $clock,
        private SerializedLedgerWrite $ledgerWrite,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * Lo que la revision diria, sin escribir nada. Falla igual que `handle()`
     * si el asiento no se puede revisar.
     *
     * @throws PurgeReviewRejected
     */
    public function preview(int $purgeAuditEntryId, ?int $responsibleUserId = null): PurgeReviewPreview
    {
        if ($responsibleUserId !== null) {
            $this->assertResponsible($responsibleUserId);
        }

        $assessment = $this->assess($purgeAuditEntryId);

        return new PurgeReviewPreview(
            purgeAuditEntryId: $purgeAuditEntryId,
            violations: $assessment['violations'],
            explainsDeletions: $assessment['explains'],
            cutoffDate: $assessment['purge']->cutoffDate,
            siteId: $assessment['purge']->siteId,
            floorYears: $assessment['floor'],
        );
    }

    /**
     * @throws PurgeReviewRejected
     */
    public function handle(ReviewOutOfBoundsPurgeCommand $command): PurgeReviewOutcome
    {
        // La medida comun (V1-RV-03): `PurgeReviewRejected` es un rechazo de
        // negocio (`BusinessRejection`) y cuenta como `rejected`, no como averia.
        return UseCaseInstrumentation::run(
            'compliance.review_out_of_bounds_purge',
            function () use ($command): PurgeReviewOutcome {
                SpanScope::annotateCurrent(['audit_entry_id' => $command->purgeAuditEntryId]);

                return $this->review($command);
            },
            static fn (PurgeReviewOutcome $outcome): array => [
                'review_entry_id' => $outcome->reviewAuditEntryId,
                'explains_deletions' => $outcome->explainsDeletions,
            ],
        );
    }

    private function review(ReviewOutOfBoundsPurgeCommand $command): PurgeReviewOutcome
    {
        // 1. Fuera del candado: no dependen de la cadena.
        $reason = PurgeReviewReason::fromText($command->reason);

        $this->assertResponsible($command->responsibleUserId);

        // 2-5. Dentro del candado de la cadena.
        return $this->ledgerWrite->withChainLock(function () use ($command, $reason): PurgeReviewOutcome {
            // Otra vez con la cadena tomada: la cuenta no puede darse de baja ni
            // perder el rol hasta que el asiento confirme.
            $this->assertResponsible($command->responsibleUserId);

            $assessment = $this->assess($command->purgeAuditEntryId);
            $purge = $assessment['purge'];

            $entry = $this->audit->handle(new RecordAuditEntryCommand(
                actor: AuditActor::user($command->responsibleUserId),
                action: AuditAction::RetentionPurgeReviewed,
                subject: AuditSubject::of('retention'),
                payload: AuditPayload::of([
                    'reviewed_audit_entry_id' => $purge->auditEntryId,
                    'reviewed_action' => AuditAction::RetentionPurgeExecuted->value,
                    'site_id' => $purge->siteId,
                    'cutoff_date' => $purge->cutoffDate,
                    'retention_years' => $purge->retentionYears,
                    'retention_floor_years' => $assessment['floor'],
                    'violations' => $assessment['violations'],
                    'explains_deletions' => $assessment['explains'],
                    'reason' => $reason->text,
                    'responsible_user_id' => $command->responsibleUserId,
                    'channel' => 'console',
                ]),
            ));

            return new PurgeReviewOutcome(
                purgeAuditEntryId: $purge->auditEntryId,
                reviewAuditEntryId: (int) $entry->id,
                violations: $assessment['violations'],
                explainsDeletions: $assessment['explains'],
                cutoffDate: $purge->cutoffDate,
                siteId: $purge->siteId,
                floorYears: $assessment['floor'],
            );
        });
    }

    /**
     * @throws PurgeReviewRejected
     */
    private function assertResponsible(int $userId): void
    {
        if (! $this->accounts->isActiveManagementAccount($userId, self::RESPONSIBLE_ROLES)) {
            throw PurgeReviewRejected::responsibleNotActive($userId);
        }
    }

    /**
     * El asiento, su suelo efectivo y sus defectos, o el motivo por el que no
     * se puede revisar.
     *
     * @return array{purge: AuditedPurge, floor: int, violations: list<string>, explains: bool}
     *
     * @throws PurgeReviewRejected
     */
    private function assess(int $purgeAuditEntryId): array
    {
        $purge = $this->ledger->purge($purgeAuditEntryId);

        if ($purge === null) {
            throw PurgeReviewRejected::notAWorkRecordPurge($purgeAuditEntryId);
        }

        $floor = $this->floorsFor($purge)->effectiveFor($purge);
        $violations = WorkRecordPurgeBoundary::violations($purge, $this->clock->now(), $floor);

        if ($violations === []) {
            throw PurgeReviewRejected::purgeIsAdmissible($purgeAuditEntryId);
        }

        $review = $this->ledger->reviewOf($purge);

        if ($review !== null) {
            throw PurgeReviewRejected::alreadyReviewed($purgeAuditEntryId, $review);
        }

        return [
            'purge' => $purge,
            'floor' => $floor,
            'violations' => $violations,
            'explains' => WorkRecordPurgeBoundary::explainsOnceReviewed($purge, $violations),
        ];
    }

    /**
     * El suelo legal del centro del asiento o, si no trae centro, el del centro
     * de la instalacion (ADR-040: hay uno). La cuenta del suelo efectivo es la
     * de {@see PurgeFloors}, la misma que usa la conciliacion.
     */
    private function floorsFor(AuditedPurge $purge): PurgeFloors
    {
        if ($purge->siteId !== null) {
            return PurgeFloors::of([$purge->siteId => $this->floors->forSite($purge->siteId)]);
        }

        $site = $this->sites->installationSite();

        return PurgeFloors::of([], $site === null ? null : $this->floors->forSite($site->id));
    }
}
