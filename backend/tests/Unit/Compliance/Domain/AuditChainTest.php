<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\Exception\AuditInstantIsNotUtc;
use App\Modules\Compliance\Domain\Exception\AuditPayloadIsNotCanonical;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use Tests\Support\Time\Instants;

/*
 * El calculo de la cadena de hash (doc 02 §7.4, RS-07), con VECTORES FIJOS y sin
 * base de datos.
 *
 * Por que con vectores literales y no comparando el calculo consigo mismo. Una
 * prueba que dijera «hashFor() da lo mismo dos veces» pasaria aunque alguien
 * cambiara el algoritmo, la semilla o el orden de los componentes: la cadena
 * seguiria siendo consistente hacia delante y **toda la auditoria escrita antes
 * dejaria de verificar**. El valor escrito a mano es lo unico que ata la
 * implementacion de hoy a la de la primera fila que se escribio.
 */

/**
 * Un borrador fijo, para que los vectores de esta prueba sean reproducibles.
 *
 * @param  array<array-key, mixed>  $payload
 */
function auditDraft(array $payload = [], ?string $occurredAt = null): AuditEntryDraft
{
    return new AuditEntryDraft(
        occurredAt: Instants::utc($occurredAt ?? '2026-08-19 06:00:00'),
        actor: AuditActor::device(7),
        action: AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry', 42),
        payload: AuditPayload::of($payload),
    );
}

// --- La genesis --------------------------------------------------------------

it('usa SHA256 de la semilla literal del documento como genesis', function (): void {
    // Doc 02 §7.4, literal: la entrada genesis usa
    // prev_hash = SHA256("FICHAJE-HOTEL-GENESIS").
    expect(AuditChain::GENESIS_SEED)->toBe('FICHAJE-HOTEL-GENESIS')
        ->and(AuditChain::genesisHash())
        ->toBe('5a4bce588b4e0fa301a7a7befe42825a5d44ec5b90d26697b300acca0add5f2e');
})->group('RS-07');

it('produce un hash de 64 caracteres hexadecimales en minusculas', function (): void {
    // Es lo que exige `audit_log_chk_hash_format` en la migracion. Si el
    // algoritmo cambiara a uno de otra longitud, la restriccion rechazaria la
    // primera escritura y nadie sabria por que.
    $entry = AuditChain::link(auditDraft(), AuditChain::genesisHash());

    expect($entry->hash)->toMatch('/^[0-9a-f]{64}$/');
})->group('RS-07');

// --- Vectores fijos del calculo ----------------------------------------------

it('calcula el hash de la entrada genesis con un vector fijo', function (): void {
    $hash = AuditChain::hashFor(auditDraft(), AuditChain::genesisHash());

    expect($hash)->toBe('52c363a96c2614e65969d5bd5ee1e30499ccc8db648afb78afda9ada26fe221c');
})->group('RS-07');

it('encadena: el mismo hecho detras de otro eslabon da otro hash', function (): void {
    // Es la propiedad que hace la cadena: no basta con que el contenido este
    // firmado, tiene que estarlo su POSICION. Sin esto, reordenar filas seria
    // indetectable.
    $draft = auditDraft();

    $first = AuditChain::hashFor($draft, AuditChain::genesisHash());
    $second = AuditChain::hashFor($draft, $first);

    expect($second)->not->toBe($first);
})->group('RS-07');

it('cambia el hash si cambia cualquiera de los seis componentes', function (callable $mutate): void {
    $base = AuditChain::hashFor(auditDraft(), AuditChain::genesisHash());

    /** @var AuditEntryDraft $mutated */
    $mutated = $mutate();

    expect(AuditChain::hashFor($mutated, AuditChain::genesisHash()))->not->toBe($base);
})->with([
    'occurred_at' => [fn (): AuditEntryDraft => new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:01'),
        AuditActor::device(7),
        AuditAction::ShiftEntryCreated,
        AuditSubject::of('shift_entry', 42),
        AuditPayload::empty(),
    )],
    'actor' => [fn (): AuditEntryDraft => new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:00'),
        AuditActor::device(8),
        AuditAction::ShiftEntryCreated,
        AuditSubject::of('shift_entry', 42),
        AuditPayload::empty(),
    )],
    'action' => [fn (): AuditEntryDraft => new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:00'),
        AuditActor::device(7),
        AuditAction::ShiftEntryVoided,
        AuditSubject::of('shift_entry', 42),
        AuditPayload::empty(),
    )],
    'subject' => [fn (): AuditEntryDraft => new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:00'),
        AuditActor::device(7),
        AuditAction::ShiftEntryCreated,
        AuditSubject::of('shift_entry', 43),
        AuditPayload::empty(),
    )],
    'payload' => [fn (): AuditEntryDraft => new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:00'),
        AuditActor::device(7),
        AuditAction::ShiftEntryCreated,
        AuditSubject::of('shift_entry', 42),
        AuditPayload::of(['work_date' => '2026-08-19']),
    )],
])->group('RS-07');

it('no confunde el limite entre accion y sujeto', function (): void {
    // Sin separador de componentes, la concatenacion tendria fronteras
    // ambiguas y dos hechos distintos podrian producir la misma cadena. Aqui se
    // mueve un caracter de un componente al siguiente: el hash TIENE que
    // cambiar.
    $one = new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:00'),
        AuditActor::system(),
        AuditAction::ShiftEntryCreated,
        AuditSubject::of('shift', 1),
        AuditPayload::empty(),
    );

    $other = new AuditEntryDraft(
        Instants::utc('2026-08-19 06:00:00'),
        AuditActor::system(),
        AuditAction::ShiftEntryCreated,
        AuditSubject::of('shift_', 1),
        AuditPayload::empty(),
    );

    expect(AuditChain::hashFor($one, AuditChain::genesisHash()))
        ->not->toBe(AuditChain::hashFor($other, AuditChain::genesisHash()));
})->group('RS-07');

// --- Serializacion canonica del payload --------------------------------------

it('da el mismo hash con las claves del payload en distinto orden de insercion', function (): void {
    // Es la condicion sin la cual la cadena no prueba nada: el mismo hecho tiene
    // que producir el mismo texto. PostgreSQL ademas NO conserva el orden de las
    // claves de un jsonb, asi que sin esto el verificador denunciaria una
    // manipulacion en cada fila con mas de una clave.
    $first = auditDraft(['zeta' => 1, 'alfa' => 2, 'media' => ['b' => 1, 'a' => 2]]);
    $second = auditDraft(['media' => ['a' => 2, 'b' => 1], 'alfa' => 2, 'zeta' => 1]);

    expect(AuditChain::hashFor($first, AuditChain::genesisHash()))
        ->toBe(AuditChain::hashFor($second, AuditChain::genesisHash()));
})->group('RS-07');

it('conserva el orden de las listas, que si es informacion', function (): void {
    $ascending = auditDraft(['reasons' => ['a', 'b']]);
    $descending = auditDraft(['reasons' => ['b', 'a']]);

    expect(AuditChain::hashFor($ascending, AuditChain::genesisHash()))
        ->not->toBe(AuditChain::hashFor($descending, AuditChain::genesisHash()));
})->group('RS-07');

it('serializa el payload sin escapar barras ni caracteres no ASCII', function (): void {
    // El escapado es OPCIONAL en JSON y por tanto una fuente de divergencia
    // entre implementaciones. Se fija el que no escapa (UTF-8 literal).
    $payload = AuditPayload::of(['path' => 'a/b', 'text' => 'jornada']);

    expect($payload->encode())->toBe('{"path":"a/b","text":"jornada"}');
})->group('RS-07');

it('serializa un payload vacio como objeto vacio', function (): void {
    expect(AuditPayload::empty()->encode())->toBe('{}');
})->group('RS-07');

it('rechaza un payload con un objeto dentro', function (): void {
    // Un objeto se serializa segun como este implementado HOY. El dia que gane
    // una propiedad, el mismo hecho produciria otro hash y el verificador
    // denunciaria una rotura inexistente.
    AuditPayload::of(['when' => new DateTimeImmutable('2026-08-19')]);
})->throws(AuditPayloadIsNotCanonical::class)->group('RS-07');

it('rechaza un payload con un flotante no finito', function (): void {
    AuditPayload::of(['ratio' => INF]);
})->throws(AuditPayloadIsNotCanonical::class)->group('RS-07');

it('rechaza un payload con una cadena que no es UTF-8', function (): void {
    AuditPayload::of(['name' => "\xB1\x31"]);
})->throws(AuditPayloadIsNotCanonical::class)->group('RS-07');

// --- Regla dura 3 ------------------------------------------------------------

it('rechaza una entrada cuyo instante no esta en UTC', function (): void {
    // La cadena se calcula sobre el valor UTC, no sobre una representacion
    // local. Convertir en silencio esconderia que alguien escribe con la zona
    // equivocada; rechazar lo expone donde se comete.
    new AuditEntryDraft(
        new DateTimeImmutable('2026-08-19 08:00:00', new DateTimeZone('Europe/Madrid')),
        AuditActor::system(),
        AuditAction::ShiftEntryCreated,
        AuditSubject::none(),
        AuditPayload::empty(),
    );
})->throws(AuditInstantIsNotUtc::class)->group('RS-07');

it('distingue dos instantes que solo se diferencian en microsegundos', function (): void {
    // Por esto la columna es TIMESTAMPTZ(6) y no la precision de serie de
    // Laravel, que es 0: si la base redondeara al segundo, el verificador
    // recalcularia manana un hash distinto del escrito hoy.
    $first = auditDraft([], '2026-08-19 06:00:00.000000');
    $second = auditDraft([], '2026-08-19 06:00:00.000001');

    expect(AuditChain::hashFor($first, AuditChain::genesisHash()))
        ->not->toBe(AuditChain::hashFor($second, AuditChain::genesisHash()));
})->group('RS-07');

it('compara hashes en tiempo constante', function (): void {
    expect(AuditChain::matches('abc', 'abc'))->toBeTrue()
        ->and(AuditChain::matches('abc', 'abd'))->toBeFalse();
})->group('RS-07');
