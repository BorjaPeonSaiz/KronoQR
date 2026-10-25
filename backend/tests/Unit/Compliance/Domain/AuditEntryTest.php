<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;

/*
 * Un asiento ya encadenado lleva hashes con la forma del `CHECK` de
 * `audit_log` (RS-07, ADR-010, H5).
 *
 * Un hash truncado o en mayusculas nunca casaria con el recalculado y pareceria
 * una manipulacion; al escribir, seria una fila que la tabla rechaza a
 * destiempo.
 */

function asientoDeAuditoriaBorrador(): AuditEntryDraft
{
    return new AuditEntryDraft(
        occurredAt: new DateTimeImmutable('2026-09-08T09:07:33.123456Z'),
        actor: AuditActor::device(7),
        action: AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry', 42),
        payload: AuditPayload::of([]),
    );
}

it('acepta el asiento que encadena AuditChain, con y sin identificador', function (): void {
    $entry = AuditChain::link(asientoDeAuditoriaBorrador(), AuditChain::genesisHash());

    expect($entry->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($entry->withId(1)->id)->toBe(1)
        ->and(new AuditEntry($entry->draft, '', $entry->hash, 2)->previousHash)->toBe('');
})->group('RS-07');

it('rechaza un hash o un identificador que la tabla no admite', function (string $previous, string $hash, ?int $id): void {
    expect(static fn (): AuditEntry => new AuditEntry(asientoDeAuditoriaBorrador(), $previous, $hash, $id))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'hash truncado' => [str_repeat('a', 64), str_repeat('b', 63), null],
    'hash largo' => [str_repeat('a', 64), str_repeat('b', 65), null],
    'hash en mayusculas' => [str_repeat('a', 64), str_repeat('B', 64), null],
    'hash vacio' => [str_repeat('a', 64), '', null],
    'hash con salto de linea' => [str_repeat('a', 64), str_repeat('b', 64)."\n", null],
    'eslabon anterior truncado' => [str_repeat('a', 10), str_repeat('b', 64), null],
    'eslabon anterior que no es hexadecimal' => [str_repeat('g', 64), str_repeat('b', 64), null],
    'identificador cero' => [str_repeat('a', 64), str_repeat('b', 64), 0],
    'identificador negativo' => [str_repeat('a', 64), str_repeat('b', 64), -1],
])->group('RS-07');
