<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditChainBreakKind;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use Tests\Support\Compliance\InMemoryAuditChainReader;
use Tests\Support\Compliance\RecordingAuditMetrics;
use Tests\Support\Time\FixedClock;
use Tests\Support\Time\Instants;

/*
 * El verificador de RS-07, con la cadena en memoria y sin base de datos.
 *
 * La prueba de que detecta una alteracion escrita por SQL directo es de
 * integracion (tests/Integration/Compliance) y es la que exige el escenario
 * ineludible del doc 02 §9.4. Aqui se cubre lo OTRO: los tres desenlaces del
 * arranque de la cadena de ADR-027 —genesis, purga sellada y manipulacion—, que
 * son la diferencia entre una alerta util y una que suena todos los dias.
 */

/**
 * @param  array<array-key, mixed>  $payload
 */
function chainDraft(int $minute, array $payload = []): AuditEntryDraft
{
    return new AuditEntryDraft(
        occurredAt: Instants::utc(sprintf('2026-08-19 06:%02d:00', $minute)),
        actor: AuditActor::system(),
        action: AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry', $minute),
        payload: AuditPayload::of($payload),
    );
}

/**
 * Una cadena bien formada de `$length` entradas a partir de `$from`.
 *
 * @return list<AuditEntry>
 */
function intactChain(int $length, ?string $from = null): array
{
    $previous = $from ?? AuditChain::genesisHash();
    $entries = [];

    for ($i = 0; $i < $length; $i++) {
        $entry = AuditChain::link(chainDraft($i), $previous)->withId($i + 1);
        $entries[] = $entry;
        $previous = $entry->hash;
    }

    return $entries;
}

function verifierFor(InMemoryAuditChainReader $reader, RecordingAuditMetrics $metrics): VerifyAuditChain
{
    return new VerifyAuditChain($reader, $metrics, FixedClock::at('2026-08-20 04:05:00'));
}

it('da por integra una cadena bien formada y deja la metrica en cero', function (): void {
    $metrics = new RecordingAuditMetrics;

    $result = verifierFor(new InMemoryAuditChainReader(intactChain(5)), $metrics)->handle();

    expect($result->isIntact())->toBeTrue()
        ->and($result->rowsVerified)->toBe(5)
        ->and($metrics->failuresTotal)->toBe(0);
})->group('RS-07');

it('da por integra una tabla vacia', function (): void {
    // Una instalacion recien entregada. El verificador diario tiene que salir en
    // verde el primer dia, antes del primer fichaje.
    $metrics = new RecordingAuditMetrics;

    $result = verifierFor(new InMemoryAuditChainReader, $metrics)->handle();

    expect($result->isIntact())->toBeTrue()
        ->and($result->rowsVerified)->toBe(0)
        ->and($metrics->failuresTotal)->toBe(0);
})->group('RS-07');

it('detecta una entrada cuyo contenido no produce su hash', function (): void {
    // El escenario del §9.4: alguien cambio un campo despues de escribir la
    // fila. El hash almacenado sigue siendo el de antes.
    $chain = intactChain(3);

    $tampered = new AuditEntry(
        chainDraft(1, ['minutes' => 999]),   // contenido distinto
        $chain[1]->previousHash,
        $chain[1]->hash,                     // hash de antes
        2,
    );

    $metrics = new RecordingAuditMetrics;
    $reader = new InMemoryAuditChainReader([$chain[0], $tampered, $chain[2]]);

    $result = verifierFor($reader, $metrics)->handle();

    expect($result->isIntact())->toBeFalse()
        ->and($result->failureCount())->toBe(1)
        ->and($result->breaks[0]->kind)->toBe(AuditChainBreakKind::ContentAltered)
        ->and($result->breaks[0]->entryId)->toBe(2)
        ->and($metrics->failuresTotal)->toBe(1);
})->group('RS-07');

it('no arrastra el hallazgo a la entrada siguiente, que esta intacta', function (): void {
    // Si el verificador siguiera con el hash RECALCULADO, marcaria tambien la
    // fila de despues y un incidente de una fila pareceria uno de cien. Una
    // rotura, un hallazgo.
    $chain = intactChain(3);

    $tampered = new AuditEntry(chainDraft(1, ['x' => 1]), $chain[1]->previousHash, $chain[1]->hash, 2);
    $reader = new InMemoryAuditChainReader([$chain[0], $tampered, $chain[2]]);

    $result = verifierFor($reader, new RecordingAuditMetrics)->handle();

    expect($result->failureCount())->toBe(1);
})->group('RS-07');

it('detecta que falta una entrada entre dos eslabones', function (): void {
    $chain = intactChain(3);

    $reader = new InMemoryAuditChainReader([$chain[0], $chain[2]]);

    $result = verifierFor($reader, new RecordingAuditMetrics)->handle();

    expect($result->failureCount())->toBe(1)
        ->and($result->breaks[0]->kind)->toBe(AuditChainBreakKind::BrokenLink);
})->group('RS-07');

it('reconoce una purga sellada y no la denuncia como rotura', function (): void {
    // ADR-027. Sin esto, la retencion de RL-02 haria sonar la alerta critica
    // TODOS LOS DIAS y alguien acabaria silenciandola.
    $sealedLast = str_repeat('a', 64);

    $chain = intactChain(2, $sealedLast);
    $anchor = new AuditChainAnchor(
        partitionYear: 2026,
        firstHash: str_repeat('b', 64),
        lastHash: $sealedLast,
        rowCount: 1200,
        sealedAt: Instants::utc('2030-01-02 03:00:00'),
        sealedBy: 'fichaje_maintenance',
    );

    $metrics = new RecordingAuditMetrics;
    $result = verifierFor(new InMemoryAuditChainReader($chain, [$anchor]), $metrics)->handle();

    expect($result->isIntact())->toBeTrue()
        ->and($result->sealedPurgeYears)->toBe([2026])
        ->and($metrics->failuresTotal)->toBe(0);
})->group('RS-07');

it('denuncia un arranque huerfano que no encaja con ningun ancla', function (): void {
    // El negativo de la anterior, y hacen falta las dos: «faltan filas» frente a
    // «faltan filas que alguien registro que iba a quitar, y encajan».
    $chain = intactChain(2, str_repeat('c', 64));

    $metrics = new RecordingAuditMetrics;
    $result = verifierFor(new InMemoryAuditChainReader($chain), $metrics)->handle();

    expect($result->isIntact())->toBeFalse()
        ->and($result->breaks[0]->kind)->toBe(AuditChainBreakKind::OrphanStart)
        ->and($metrics->failuresTotal)->toBe(1);
})->group('RS-07');

it('publica el resultado como metrica tambien cuando esta todo bien', function (): void {
    // Una serie que desaparece es indistinguible de una que nunca fallo, y la
    // regla `absent()` de audit.yml es la que descubre que el comando programado
    // dejo de ejecutarse.
    $metrics = new RecordingAuditMetrics;

    verifierFor(new InMemoryAuditChainReader(intactChain(2)), $metrics)->handle();

    expect($metrics->lastVerification)->not->toBeNull()
        ->and($metrics->lastVerification?->rowsVerified)->toBe(2);
})->group('RS-07');
