<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\ReviewOutOfBoundsPurgeCommand;
use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\Port\PurgeReviewLedger;
use App\Modules\Compliance\Application\Port\ResponsibleAccounts;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Application\UseCase\ReviewOutOfBoundsPurge;
use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use Tests\Support\Compliance\FixedRetentionYearsFloor;
use Tests\Support\Time\FixedClock;

/*
 * Dar por revisada una purga no admisible (RL-04, RS-07, ADR-057 enmienda del
 * 10-10-2026 §6), con los puertos en memoria: las seis negativas, que ninguna
 * escribe, que las comprobaciones de la cadena caen dentro del candado y el
 * payload exacto del asiento.
 *
 * La prueba con PostgreSQL y la orden de consola es
 * tests/Feature/Compliance/ReconcileWorkRecordAcknowledgeTest.php.
 */

const REVIEW_OUT_OF_BOUNDS_PURGE_REASON = 'Incidente INC-2030-07: el perfil se bajo a 3 por error y se subio a 4 tras investigarlo.';

/** Lo que pasa, en orden: lecturas del libro, candado y asientos. */
final class ReviewPurgeJournal
{
    /** @var list<string> */
    public array $steps = [];

    /** @var list<AuditEntryDraft> */
    public array $written = [];
}

/**
 * El caso de uso con una purga en memoria.
 *
 * @param  array<int, AuditedPurge>  $purges
 * @param  array<int, int>  $reviews  Purga => revision ya escrita.
 * @param  list<int>  $activeAccounts
 * @param  array<int, int>  $floorsBySite
 */
function reviewPurgeUseCase(
    ReviewPurgeJournal $journal,
    array $purges = [],
    array $reviews = [],
    array $activeAccounts = [7],
    array $floorsBySite = [1 => 4],
    ?int $installationSite = 1,
    ?FixedRetentionYearsFloor $floors = null,
): ReviewOutOfBoundsPurge {
    $ledger = new class($journal, $purges, $reviews) implements PurgeReviewLedger
    {
        /**
         * @param  array<int, AuditedPurge>  $purges
         * @param  array<int, int>  $reviews
         */
        public function __construct(private ReviewPurgeJournal $journal, private array $purges, private array $reviews) {}

        public function purge(int $auditEntryId): ?AuditedPurge
        {
            $this->journal->steps[] = 'purge';

            return $this->purges[$auditEntryId] ?? null;
        }

        public function reviewOf(AuditedPurge $purge): ?int
        {
            $this->journal->steps[] = 'reviewOf';

            return $this->reviews[$purge->auditEntryId] ?? null;
        }
    };

    $accounts = new class($activeAccounts) implements ResponsibleAccounts
    {
        /** @param list<int> $active */
        public function __construct(private array $active) {}

        public function isActiveManagementAccount(int $userId): bool
        {
            return in_array($userId, $this->active, true);
        }
    };

    $sites = new class($installationSite) implements InstallationSiteProvider
    {
        public function __construct(private ?int $siteId) {}

        public function installationSite(): ?InstallationSite
        {
            return $this->siteId === null ? null : new InstallationSite($this->siteId, 'Centro', 'Europe/Madrid');
        }
    };

    $lock = new class($journal) implements SerializedLedgerWrite
    {
        public function __construct(private ReviewPurgeJournal $journal) {}

        public function withChainLock(callable $work): mixed
        {
            $this->journal->steps[] = 'lock';
            $result = $work();
            $this->journal->steps[] = 'unlock';

            return $result;
        }
    };

    $trail = new class($journal) implements AuditTrail
    {
        public function __construct(private ReviewPurgeJournal $journal) {}

        public function append(AuditEntryDraft $draft): AuditEntry
        {
            $this->journal->steps[] = 'append';
            $this->journal->written[] = $draft;

            return new AuditEntry($draft, 'previo', 'hash', 500 + count($this->journal->written));
        }
    };

    $clock = FixedClock::at('2031-01-01 00:00:00');

    return new ReviewOutOfBoundsPurge(
        ledger: $ledger,
        accounts: $accounts,
        floors: $floors ?? new FixedRetentionYearsFloor(1, $floorsBySite),
        sites: $sites,
        clock: $clock,
        ledgerWrite: $lock,
        audit: new RecordAuditEntry($trail, $clock),
    );
}

/** Purga del centro 1 a tres años con el corte que su plazo permitia: solo le falla el suelo español. */
function reviewPurgeSpanishThreeYears(int $id = 90, ?int $site = 1, string $cutoff = '2026-10-08', ?int $declaredFloor = null): AuditedPurge
{
    return new AuditedPurge($id, new DateTimeImmutable('2029-10-08T05:00:00Z'), $cutoff, 3, $site, $declaredFloor);
}

function reviewPurgeRejection(callable $action): ?PurgeReviewRejected
{
    try {
        $action();
    } catch (PurgeReviewRejected $rejected) {
        return $rejected;
    }

    return null;
}

it('escribe el asiento de revision con el payload exacto, dentro del candado', function (): void {
    $journal = new ReviewPurgeJournal;
    $outcome = reviewPurgeUseCase($journal, [90 => reviewPurgeSpanishThreeYears()])
        ->handle(new ReviewOutOfBoundsPurgeCommand(90, '  '.REVIEW_OUT_OF_BOUNDS_PURGE_REASON.'  ', 7));

    expect($journal->steps)->toBe(['lock', 'purge', 'reviewOf', 'append', 'unlock'])
        ->and($journal->written)->toHaveCount(1)
        ->and($journal->written[0]->action->known())->toBe(AuditAction::RetentionPurgeReviewed)
        ->and($journal->written[0]->actor->canonical())->toBe(AuditActor::user(7)->canonical())
        ->and($journal->written[0]->subject->type)->toBe('retention')
        ->and($journal->written[0]->subject->id)->toBeNull()
        ->and($journal->written[0]->occurredAt->format(DATE_ATOM))->toBe('2031-01-01T00:00:00+00:00')
        ->and($journal->written[0]->payload->data)->toBe([
            'reviewed_audit_entry_id' => 90,
            'reviewed_action' => 'retention.purge_executed',
            'site_id' => 1,
            'cutoff_date' => '2026-10-08',
            'retention_years' => 3,
            'retention_floor_years' => 4,
            'violations' => ['retention_years'],
            'explains_deletions' => true,
            'reason' => REVIEW_OUT_OF_BOUNDS_PURGE_REASON,
            'responsible_user_id' => 7,
            'channel' => 'console',
        ])
        ->and($outcome->purgeAuditEntryId)->toBe(90)
        ->and($outcome->reviewAuditEntryId)->toBe(501)
        ->and($outcome->violations)->toBe(['retention_years'])
        ->and($outcome->explainsDeletions)->toBeTrue()
        ->and($outcome->cutoffDate)->toBe('2026-10-08')
        ->and($outcome->siteId)->toBe(1)
        ->and($outcome->floorYears)->toBe(4);
})->group('RL-04', 'RS-07');

it('no explica borrados cuando el defecto no es solo el plazo', function (): void {
    // Corte posterior a lo que permitian tres años y sin centro: se puede dar
    // por revisada, pero no vuelve a explicar nada.
    $journal = new ReviewPurgeJournal;
    $outcome = reviewPurgeUseCase($journal, [90 => reviewPurgeSpanishThreeYears(site: null, cutoff: '2027-10-08')])
        ->handle(new ReviewOutOfBoundsPurgeCommand(90, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 7));

    expect($outcome->explainsDeletions)->toBeFalse()
        ->and($outcome->violations)->toBe(['retention_years', 'site_id'])
        ->and($journal->written[0]->payload->data['site_id'])->toBeNull()
        ->and($journal->written[0]->payload->data['explains_deletions'])->toBeFalse()
        // Sin centro en el asiento, el suelo es el del centro de la instalacion.
        ->and($outcome->floorYears)->toBe(4);
})->group('RL-04', 'RS-07');

it('juzga con el suelo declarado por el asiento si es mayor que el del centro', function (): void {
    $journal = new ReviewPurgeJournal;
    $outcome = reviewPurgeUseCase($journal, [90 => reviewPurgeSpanishThreeYears(declaredFloor: 5)], floorsBySite: [1 => 1])
        ->handle(new ReviewOutOfBoundsPurgeCommand(90, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 7));

    expect($outcome->floorYears)->toBe(5)
        ->and($journal->written[0]->payload->data['retention_floor_years'])->toBe(5);
})->group('RL-02', 'RS-07');

it('usa el suelo minimo si ni el asiento ni la instalacion tienen centro', function (): void {
    $floors = new FixedRetentionYearsFloor(4);
    $journal = new ReviewPurgeJournal;
    $outcome = reviewPurgeUseCase($journal, [90 => reviewPurgeSpanishThreeYears(site: null)], installationSite: null, floors: $floors)
        ->handle(new ReviewOutOfBoundsPurgeCommand(90, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 7));

    expect($outcome->floorYears)->toBe(1)
        ->and($outcome->violations)->toBe(['site_id'])
        ->and($floors->askedSites)->toBe([]);
})->group('RL-02', 'RS-07');

it('rechaza sin escribir nada', function (Closure $scenario, string $key, array $parameters, array $steps): void {
    $journal = new ReviewPurgeJournal;
    $rejected = reviewPurgeRejection(fn (): mixed => $scenario($journal));

    expect($rejected?->translationKey)->toBe($key)
        ->and($rejected?->parameters)->toBe($parameters)
        ->and($journal->written)->toBe([])
        ->and($journal->steps)->toBe($steps);
})->with([
    'no es una purga' => [
        fn (ReviewPurgeJournal $j): mixed => reviewPurgeUseCase($j)->handle(new ReviewOutOfBoundsPurgeCommand(91, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 7)),
        'work-record-review.not_a_purge', ['id' => 91], ['lock', 'purge'],
    ],
    'ya revisada' => [
        fn (ReviewPurgeJournal $j): mixed => reviewPurgeUseCase($j, [90 => reviewPurgeSpanishThreeYears()], [90 => 95])
            ->handle(new ReviewOutOfBoundsPurgeCommand(90, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 7)),
        'work-record-review.already_reviewed', ['id' => 90, 'review' => 95], ['lock', 'purge', 'reviewOf'],
    ],
    'admisible' => [
        fn (ReviewPurgeJournal $j): mixed => reviewPurgeUseCase($j, [90 => reviewPurgeSpanishThreeYears()], floorsBySite: [1 => 3])
            ->handle(new ReviewOutOfBoundsPurgeCommand(90, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 7)),
        'work-record-review.admissible', ['id' => 90], ['lock', 'purge'],
    ],
    'motivo corto' => [
        fn (ReviewPurgeJournal $j): mixed => reviewPurgeUseCase($j, [90 => reviewPurgeSpanishThreeYears()])
            ->handle(new ReviewOutOfBoundsPurgeCommand(90, 'revisado', 7)),
        'work-record-review.reason_too_short', ['min' => 10], [],
    ],
    'motivo largo' => [
        fn (ReviewPurgeJournal $j): mixed => reviewPurgeUseCase($j, [90 => reviewPurgeSpanishThreeYears()])
            ->handle(new ReviewOutOfBoundsPurgeCommand(90, str_repeat('x', 1001), 7)),
        'work-record-review.reason_too_long', ['max' => 1000], [],
    ],
    'responsable inactivo' => [
        fn (ReviewPurgeJournal $j): mixed => reviewPurgeUseCase($j, [90 => reviewPurgeSpanishThreeYears()])
            ->handle(new ReviewOutOfBoundsPurgeCommand(90, REVIEW_OUT_OF_BOUNDS_PURGE_REASON, 8)),
        'work-record-review.responsible_inactive', ['id' => 8], [],
    ],
])->group('RL-04', 'RS-07');

it('enseña la vista previa sin candado y sin escribir', function (): void {
    $journal = new ReviewPurgeJournal;
    $preview = reviewPurgeUseCase($journal, [90 => reviewPurgeSpanishThreeYears()])->preview(90);

    expect($journal->steps)->toBe(['purge', 'reviewOf'])
        ->and($journal->written)->toBe([])
        ->and($preview->purgeAuditEntryId)->toBe(90)
        ->and($preview->violations)->toBe(['retention_years'])
        ->and($preview->explainsDeletions)->toBeTrue()
        ->and($preview->cutoffDate)->toBe('2026-10-08')
        ->and($preview->siteId)->toBe(1)
        ->and($preview->floorYears)->toBe(4);
})->group('RL-04', 'RS-07');

it('rechaza tambien en la vista previa lo que no se puede revisar', function (): void {
    $journal = new ReviewPurgeJournal;

    expect(reviewPurgeRejection(fn (): mixed => reviewPurgeUseCase($journal)->preview(91))?->translationKey)
        ->toBe('work-record-review.not_a_purge')
        ->and(reviewPurgeRejection(fn (): mixed => reviewPurgeUseCase($journal, [90 => reviewPurgeSpanishThreeYears()], [90 => 95])->preview(90))?->translationKey)
        ->toBe('work-record-review.already_reviewed')
        ->and($journal->written)->toBe([]);
})->group('RL-04', 'RS-07');
