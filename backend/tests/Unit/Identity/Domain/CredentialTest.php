<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Exception\CredentialAlreadyDelivered;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyPrinted;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Exception\CredentialNotPrintedYet;
use App\Modules\Identity\Domain\Exception\CredentialRevocationNeedsReason;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;

/*
 * El agregado `Credential` y sus tres actos (doc 01 §5.2, doc 02 §5.5,
 * RF-QR-01, RF-QR-03, RF-QR-04, RF-QR-06, ADR-034).
 */

function credentialUuid(): string
{
    return '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01';
}

function issuedAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-14T06:00:00+00:00');
}

function printedAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-17T09:30:00+00:00');
}

function deliveredAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-20T07:15:00+00:00');
}

function credentialKey(string $id = 'a3'): QrSigningKey
{
    return QrSigningKey::fromBase64($id, base64_encode('clave-de-firma-de-pruebas-KrnQR1'));
}

function credentialSecret(): CredentialSecret
{
    return CredentialSecret::fromString('7QK2mXpR9vLdN4tZbYcF1w');
}

/** Recien emitida: pendiente de imprimir, sin QR. */
function issuedCredential(?DateTimeImmutable $at = null): Credential
{
    return Credential::issue(
        uuid: credentialUuid(),
        employeeId: 7,
        issuedAt: $at ?? issuedAt(),
    );
}

/** Ya acuñada: tiene QR y puede fichar. */
function printedCredential(?QrSigningKey $key = null): Credential
{
    return issuedCredential()->printedWith(
        $key ?? credentialKey(),
        credentialSecret(),
        printedAt(),
    );
}

it('nace pendiente de imprimir y sin nada con lo que fichar', function (): void {
    // ADR-034: emitir es una anotacion administrativa. No hay token, no hay
    // firma y no hay hash, asi que en esta fila no hay nada que robar.
    $credential = issuedCredential();

    expect($credential->keyId)->toBeNull()
        ->and($credential->secretHash)->toBeNull()
        ->and($credential->printedAt)->toBeNull()
        ->and($credential->deliveredAt)->toBeNull()
        ->and($credential->revokedAt)->toBeNull()
        ->and($credential->isActive())->toBeTrue()
        ->and($credential->isPrinted())->toBeFalse()
        ->and($credential->isScannable())->toBeFalse();
})->group('RF-QR-01', 'RF-QR-08');

it('una credencial pendiente de imprimir no esta firmada con ninguna clave', function (): void {
    // Durante una rotacion (§5.3) esto es lo que evita contarla entre las que
    // faltan por reimprimir: no se firmo con la clave vieja, porque no se firmo.
    expect(issuedCredential()->signedWithKey('a3'))->toBeFalse()
        ->and(issuedCredential()->signedWithKey('a2'))->toBeFalse();
})->group('RF-QR-07');

it('la impresion acuña el QR y guarda el hash del token, nunca el token', function (): void {
    // Regla dura 10 y §5.2: quien lea la tabla no puede fabricar una tarjeta.
    $printed = printedCredential();

    expect($printed->secretHash)->not->toBe('7QK2mXpR9vLdN4tZbYcF1w')
        ->and($printed->secretHash)->toBe(hash('sha256', '7QK2mXpR9vLdN4tZbYcF1w'))
        ->and($printed->secretHash)->toHaveLength(64)
        ->and($printed->keyId)->toBe('a3')
        ->and($printed->printedAt)->toEqual(printedAt())
        ->and($printed->isScannable())->toBeTrue()
        ->and($printed->signedWithKey('a3'))->toBeTrue();
})->group('RF-QR-04', 'RS-01');

it('imprimir no muta la credencial emitida', function (): void {
    // Regla dura 5: cada transicion devuelve otra credencial.
    $credential = issuedCredential();

    $credential->printedWith(credentialKey(), credentialSecret(), printedAt());

    expect($credential->isPrinted())->toBeFalse()
        ->and($credential->secretHash)->toBeNull();
})->group('RF-QR-04');

it('firma con la clave vigente al imprimir, no con la de la emision', function (): void {
    // §5.3: una tarjeta emitida antes de rotar y llevada a imprenta despues sale
    // con la clave nueva. Si el `key_id` se fijara al emitir, saldria firmada con
    // una clave que esa misma semana se retira.
    $printed = printedCredential(credentialKey('b7'));

    expect($printed->keyId)->toBe('b7')
        ->and($printed->signedWithKey('b7'))->toBeTrue();
})->group('RF-QR-07');

it('no se imprime dos veces', function (): void {
    // El nucleo de ADR-034: «reimprimir» solo puede significar acuñar otro token,
    // y eso mata la tarjeta que quiza ya esta en un bolsillo. Es tambien lo que
    // hace inofensivo lanzar dos veces `credentials:print-batch`.
    $printed = printedCredential();

    expect(static fn () => $printed->printedWith(
        credentialKey(),
        CredentialSecret::fromString('OtroToken22Caracteres1'),
        new DateTimeImmutable('2026-08-18T09:00:00+00:00'),
    ))->toThrow(CredentialAlreadyPrinted::class);
})->group('RF-QR-04', 'RF-QR-03');

it('no imprime una credencial revocada', function (): void {
    // Produciria un QR que el quiosco rechaza en el primer escaneo, y una
    // persona delante de la tablet sin entender por que.
    $revoked = issuedCredential()->revoke('El alta se anulo', new DateTimeImmutable('2026-08-15T10:00:00+00:00'));

    expect(static fn () => $revoked->printedWith(credentialKey(), credentialSecret(), printedAt()))
        ->toThrow(CredentialAlreadyRevoked::class);
})->group('RF-QR-04', 'RF-QR-03');

it('no se imprime antes de emitirse', function (): void {
    // La misma invariante que el CHECK `credentials_chk_lifecycle_order`.
    expect(static fn () => issuedCredential()->printedWith(
        credentialKey(),
        credentialSecret(),
        new DateTimeImmutable('2026-08-13T06:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class);
})->group('RF-QR-04');

it('registra la entrega con momento y responsable', function (): void {
    // RF-QR-06. Distingue «se perdio antes de darsela» de «la perdio el
    // empleado», que son incidencias distintas (§5.5).
    $delivered = printedCredential()->deliveredBy(42, deliveredAt());

    expect($delivered->isDelivered())->toBeTrue()
        ->and($delivered->deliveredAt)->toEqual(deliveredAt())
        ->and($delivered->deliveredByUserId)->toBe(42)
        ->and($delivered->secretHash)->toBe(printedCredential()->secretHash);
})->group('RF-QR-06');

it('no entrega una credencial que todavia no se ha impreso', function (): void {
    // Antes de imprimir no hay tarjeta: marcar la entrega seria registrar un
    // acto que no ocurrio.
    expect(static fn () => issuedCredential()->deliveredBy(42, deliveredAt()))
        ->toThrow(CredentialNotPrintedYet::class);
})->group('RF-QR-06');

it('no registra dos veces la misma entrega', function (): void {
    $delivered = printedCredential()->deliveredBy(42, deliveredAt());

    expect(static fn () => $delivered->deliveredBy(43, new DateTimeImmutable('2026-08-21T07:00:00+00:00')))
        ->toThrow(CredentialAlreadyDelivered::class);
})->group('RF-QR-06');

it('no entrega una credencial revocada', function (): void {
    $revoked = printedCredential()->revoke('Extraviada en la imprenta', new DateTimeImmutable('2026-08-18T12:00:00+00:00'));

    expect(static fn () => $revoked->deliveredBy(42, deliveredAt()))
        ->toThrow(CredentialAlreadyRevoked::class);
})->group('RF-QR-06', 'RF-QR-03');

it('no se entrega antes de imprimirse', function (): void {
    expect(static fn () => printedCredential()->deliveredBy(42, new DateTimeImmutable('2026-08-16T07:00:00+00:00')))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-QR-06');

it('revocar devuelve otra credencial y conserva la anterior', function (): void {
    // Regla dura 5: nada se sobrescribe. El objeto original sigue intacto, que
    // es lo que hace imposible perder una revocacion a medias.
    $credential = printedCredential();

    $revoked = $credential->revoke('Perdida en el turno de noche', new DateTimeImmutable('2026-08-20T21:14:02+00:00'));

    expect($credential->isActive())->toBeTrue()
        ->and($revoked->isActive())->toBeFalse()
        ->and($revoked->isScannable())->toBeFalse()
        ->and($revoked->revokedReason)->toBe('Perdida en el turno de noche')
        ->and($revoked->uuid)->toBe($credential->uuid)
        ->and($revoked->secretHash)->toBe($credential->secretHash)
        ->and($revoked->printedAt)->toEqual($credential->printedAt)
        ->and($revoked->issuedAt)->toEqual($credential->issuedAt);
})->group('RF-QR-03', 'RN-13');

it('revoca tambien una credencial que nunca llego a imprimirse', function (): void {
    // Dar de alta a alguien que al final no se incorpora deja un derecho a
    // tarjeta que hay que retirar, y hacerlo con su motivo es mas honesto que
    // borrar la fila.
    $revoked = issuedCredential()->revoke('El alta se anulo', new DateTimeImmutable('2026-08-15T10:00:00+00:00'));

    expect($revoked->isActive())->toBeFalse()
        ->and($revoked->isPrinted())->toBeFalse()
        ->and($revoked->revokedReason)->toBe('El alta se anulo');
})->group('RF-QR-03');

it('no revoca dos veces', function (): void {
    // Sobrescribir la primera revocacion cambiaria el motivo y el momento que ya
    // constan en audit_log.
    $revoked = printedCredential()->revoke('Perdida', new DateTimeImmutable('2026-08-20T21:14:02+00:00'));

    expect(static fn () => $revoked->revoke('Otra cosa', new DateTimeImmutable('2026-08-21T08:00:00+00:00')))
        ->toThrow(CredentialAlreadyRevoked::class);
})->group('RF-QR-03');

it('exige motivo para revocar', function (string $reason): void {
    expect(static fn () => printedCredential()->revoke($reason, new DateTimeImmutable('2026-08-20T21:14:02+00:00')))
        ->toThrow(CredentialRevocationNeedsReason::class);
})->with([
    'vacio' => [''],
    'solo espacios' => ['   '],
])->group('RF-QR-03');

it('recorta el motivo a lo que cabe en la columna', function (): void {
    $revoked = printedCredential()->revoke(str_repeat('a', 500), new DateTimeImmutable('2026-08-20T21:14:02+00:00'));

    expect($revoked->revokedReason)->toHaveLength(Credential::MAX_REASON_LENGTH);
})->group('RF-QR-03');

it('no admite una revocacion anterior a la emision', function (): void {
    // La misma invariante que el CHECK `credentials_chk_lifecycle_order`.
    expect(static fn () => issuedCredential()->revoke('Perdida', new DateTimeImmutable('2026-08-13T06:00:00+00:00')))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-QR-03');

it('no admite media revocacion', function (): void {
    // Momento y motivo van juntos o no van, igual que en
    // `credentials_chk_revocation_has_reason`.
    $base = [
        'uuid' => credentialUuid(),
        'employeeId' => 7,
        'issuedAt' => issuedAt(),
    ];

    expect(static fn (): Credential => new Credential(...$base, revokedAt: new DateTimeImmutable('2026-08-20T00:00:00+00:00')))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn (): Credential => new Credential(...$base, revokedReason: 'Perdida'))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-QR-03');

it('no admite media impresion', function (array $minted): void {
    // Las tres marcas de la impresion van juntas: un hash sin `printed_at`
    // dejaria la tarjeta escaneable y a la vez «pendiente de imprimir» en el
    // panel; un `printed_at` sin hash, una tarjeta impresa que nadie puede usar.
    // Es el reflejo del CHECK `credentials_chk_minted_at_print`.
    $arguments = [
        'uuid' => credentialUuid(),
        'employeeId' => 7,
        'issuedAt' => issuedAt(),
        ...$minted,
    ];

    expect(static fn (): Credential => new Credential(...$arguments))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'solo la clave' => [['keyId' => 'a3']],
    'solo el hash' => [['secretHash' => 'abc']],
    'solo la fecha' => [['printedAt' => printedAt()]],
    'clave y hash sin fecha' => [['keyId' => 'a3', 'secretHash' => 'abc']],
    'fecha sin clave ni hash' => [['printedAt' => printedAt(), 'keyId' => 'a3']],
])->group('RF-QR-04');

it('no admite media entrega', function (): void {
    // Momento y responsable van juntos, igual que en
    // `credentials_chk_delivery_is_signed`.
    $base = [
        'uuid' => credentialUuid(),
        'employeeId' => 7,
        'issuedAt' => issuedAt(),
        'keyId' => 'a3',
        'secretHash' => hash('sha256', 'x'),
        'printedAt' => printedAt(),
    ];

    expect(static fn (): Credential => new Credential(...$base, deliveredAt: deliveredAt()))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn (): Credential => new Credential(...$base, deliveredByUserId: 42))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-QR-06');

it('no admite una entrega sin impresion previa', function (): void {
    expect(static fn (): Credential => new Credential(
        uuid: credentialUuid(),
        employeeId: 7,
        issuedAt: issuedAt(),
        deliveredAt: deliveredAt(),
        deliveredByUserId: 42,
    ))->toThrow(InvalidArgumentException::class);
})->group('RF-QR-06');

it('codifica los 128 bits del token en 22 caracteres base64url', function (): void {
    $secret = CredentialSecret::fromBytes(str_repeat("\x00", CredentialSecret::ENTROPY_BYTES));

    expect($secret->value)->toHaveLength(QrPayload::TOKEN_LENGTH)
        ->and($secret->value)->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and(CredentialSecret::ENTROPY_BYTES)->toBe(16);
})->group('RF-QR-01');
