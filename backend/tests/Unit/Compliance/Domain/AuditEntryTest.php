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
 * Los hashes de un asiento tienen la forma del `CHECK` de `audit_log` (RS-07,
 * ADR-010, H5), y esa forma se exige **al escribir**: `AuditChain::link`
 * rechaza un eslabon anterior mal formado y calcula el propio.
 *
 * Al leer no: una fila con un hash manipulado tras quitar el `CHECK` tiene que
 * llegar al verificador para que la denuncie en esa fila y siga con las demas.
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

it('no encadena detras de un eslabon anterior mal formado', function (string $previous): void {
    expect(static fn (): AuditEntry => AuditChain::link(asientoDeAuditoriaBorrador(), $previous))
        ->toThrow(InvalidArgumentException::class, 'SHA-256');
})->with([
    'vacio' => [''],
    'truncado' => [str_repeat('a', 63)],
    'largo' => [str_repeat('a', 65)],
    'en mayusculas' => [str_repeat('A', 64)],
    'que no es hexadecimal' => [str_repeat('g', 64)],
    'con salto de linea' => [str_repeat('a', 64)."\n"],
    'con salto de linea delante' => ["\n".str_repeat('a', 64)],
])->group('RS-07');

it('reconoce la forma de un hash de la tabla y nada mas', function (string $hash, bool $wellFormed): void {
    expect(AuditEntry::isWellFormedHash($hash))->toBe($wellFormed);
})->with([
    'la genesis' => [AuditChain::genesisHash(), true],
    'cifras y letras' => [str_repeat('0123456789abcdef', 4), true],
    'truncado' => [str_repeat('b', 63), false],
    'largo' => [str_repeat('b', 65), false],
    'en mayusculas' => [str_repeat('B', 64), false],
    'vacio' => ['', false],
    'con salto de linea' => [str_repeat('b', 64)."\n", false],
])->group('RS-07');

it('acepta tal cual la fila leida con un hash mal formado, para que el verificador la denuncie', function (string $previous, string $hash): void {
    // Lanzar aqui cortaba `audit:verify` y la retencion en la primera fila
    // manipulada, sin informar de ella ni de las siguientes.
    $entry = new AuditEntry(asientoDeAuditoriaBorrador(), $previous, $hash, 2);

    expect($entry->hash)->toBe($hash)
        ->and($entry->previousHash)->toBe($previous);
})->with([
    'hash truncado' => [str_repeat('a', 64), str_repeat('b', 63)],
    'hash en mayusculas' => [str_repeat('a', 64), str_repeat('B', 64)],
    'eslabon anterior truncado' => [str_repeat('a', 10), str_repeat('b', 64)],
])->group('RS-07');

it('rechaza un identificador que la tabla no admite', function (int $id): void {
    expect(static fn (): AuditEntry => new AuditEntry(asientoDeAuditoriaBorrador(), str_repeat('a', 64), str_repeat('b', 64), $id))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'identificador cero' => [0],
    'identificador negativo' => [-1],
])->group('RS-07');

it('da el identificador de una entrada escrita, y falla si no lo tiene (R1-AR-03)', function (): void {
    $entry = AuditChain::link(asientoDeAuditoriaBorrador(), AuditChain::genesisHash());

    expect($entry->withId(8)->persistedId())->toBe(8)
        ->and(static fn (): int => $entry->persistedId())->toThrow(LogicException::class);
})->group('RS-07', 'RL-04');
