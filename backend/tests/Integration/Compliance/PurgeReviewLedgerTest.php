<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Port\PurgeReviewLedger;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ConsoleSignature;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `DatabasePurgeReviewLedger::reviewOf()` contra PostgreSQL (ADR-057 enmienda
 * del 10-10-2026 §6, RL-04, RS-07).
 *
 * POR QUE INTEGRACION. Lo que se fija es que el SQL no decide por su cuenta si
 * una revision cuenta: `->>` da texto, y un asiento añadido con
 * `"reviewed_audit_entry_id": "90"` casaria con la comparacion como texto. La
 * conciliacion no lo cuenta (`AuditedPurgeReview` solo admite enteros), asi que
 * `--acknowledge` tampoco puede tomarlo por «ya revisado»: si no, la purga
 * quedaria pendiente para siempre y la consola diria algo falso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Dentro de las particiones de audit_log del esquema de pruebas.
    FrozenTime::at('2027-10-08 05:00:00');
    app()->setLocale('es');
});

function libroDeRevisionesPurga(int $site): int
{
    $entry = resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
        actor: AuditActor::system(),
        action: AuditAction::RetentionPurgeExecuted,
        subject: AuditSubject::of('retention'),
        payload: AuditPayload::of([
            'scope' => 'work_records',
            'cutoff_date' => '2024-10-08',
            'retention_years' => 3,
            'site_id' => $site,
            'rows' => 0,
        ]),
    ));

    return (int) $entry->id;
}

/**
 * Un `retention.purge_reviewed` escrito por fuera de la consola, con el
 * `reviewed_audit_entry_id` que se le diga.
 */
function libroDeRevisionesRevision(int|string $revisada): int
{
    $entry = resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
        actor: AuditActor::system(),
        action: AuditAction::RetentionPurgeReviewed,
        subject: AuditSubject::of('retention'),
        payload: AuditPayload::of(['reviewed_audit_entry_id' => $revisada]),
    ));

    return (int) $entry->id;
}

it('no toma por revision un asiento con el id de la purga en cadena, igual que la conciliacion', function (): void {
    $ledger = resolve(PurgeReviewLedger::class);
    $purgaId = libroDeRevisionesPurga(WorkforceFixtures::site());
    libroDeRevisionesRevision((string) $purgaId);

    $purga = $ledger->purge($purgaId);

    expect($purga)->not->toBeNull();
    assert($purga !== null);
    expect($ledger->reviewOf($purga))->toBeNull();

    // Y la consola la deja revisar de verdad, sin decir que ya lo esta.
    $resultado = Artisan::call('compliance:reconcile-work-record', [
        '--acknowledge' => (string) $purgaId,
        '--reason' => 'INC-2027-15: revision con el id en cadena, añadida por fuera.',
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::ADMIN)->id),
    ]);

    expect($resultado)->toBe(0, Artisan::output())
        ->and(DB::table('audit_log')->where('action', 'retention.purge_reviewed')->count())->toBe(2);

    $real = DB::table('audit_log')->where('action', 'retention.purge_reviewed')->max('id');

    expect($ledger->reviewOf($purga))->toBe($real);
})->group('RL-04', 'RS-07');

it('devuelve la primera revision entera y posterior', function (): void {
    $ledger = resolve(PurgeReviewLedger::class);
    $purgaId = libroDeRevisionesPurga(WorkforceFixtures::site());
    libroDeRevisionesRevision((string) $purgaId);
    $primera = libroDeRevisionesRevision($purgaId);
    libroDeRevisionesRevision($purgaId);

    $purga = $ledger->purge($purgaId);
    assert($purga !== null);

    expect($ledger->reviewOf($purga))->toBe($primera);
})->group('RL-04', 'RS-07');
