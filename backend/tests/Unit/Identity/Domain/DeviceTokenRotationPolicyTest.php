<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Policy\DeviceTokenRotationPolicy;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRecord;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRotation;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRotationOutcome;

/*
 * Cuando se rota el token del quiosco y que pasa con el anterior (RF-ID-04,
 * doc 02 §7.3, ADR-044, hallazgo F1-1).
 *
 * La politica decide por el token que FIRMO el latido. Tres caminos: el vigente
 * pasado el 80 % rota y entra en solape; el vigente antes del 80 % no hace
 * nada; uno ya relevado recibe otro relevo sin alargar su solape.
 */

function politicaDeRotacion(float $threshold = 0.8, int $overlapHours = 24): DeviceTokenRotationPolicy
{
    return new DeviceTokenRotationPolicy($threshold, $overlapHours * 3600);
}

/** Un token de 90 dias emitido el 1 de enero de 2026 a medianoche UTC. */
function tokenDeNoventaDias(int $id, string $issuedAt = '2026-01-01T00:00:00Z', ?string $expiresAt = '2026-04-01T00:00:00Z'): DeviceTokenRecord
{
    return new DeviceTokenRecord(
        $id,
        new DateTimeImmutable($issuedAt),
        $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
    );
}

function instanteDeRotacion(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at);
}

it('no rota antes del 80 % de la vida del token que firma', function (): void {
    $decision = politicaDeRotacion()->decide([tokenDeNoventaDias(7)], 7, instanteDeRotacion('2026-03-13T23:59:59Z'));

    expect($decision->outcome)->toBe(DeviceTokenRotationOutcome::NONE)
        ->and($decision->issuesToken())->toBeFalse()
        ->and($decision->retire)->toBe([]);
})->group('RF-ID-04');

it('rota en el instante exacto del 80 % y deja el firmante en solape de 24 horas', function (): void {
    $decision = politicaDeRotacion()->decide([tokenDeNoventaDias(7)], 7, instanteDeRotacion('2026-03-14T00:00:00Z'));

    expect($decision->outcome)->toBe(DeviceTokenRotationOutcome::ROTATE)
        ->and($decision->issuesToken())->toBeTrue()
        ->and($decision->shortensSuperseded())->toBeTrue()
        ->and($decision->supersededTokenId)->toBe(7)
        ->and($decision->supersededUntil?->format('Y-m-d\TH:i:s'))->toBe('2026-03-15T00:00:00')
        ->and($decision->retire)->toBe([]);
})->group('RF-ID-04');

it('el solape es configurable y no una constante', function (): void {
    $decision = politicaDeRotacion(overlapHours: 6)->decide([tokenDeNoventaDias(7)], 7, instanteDeRotacion('2026-03-20T10:00:00Z'));

    expect($decision->supersededUntil?->format('Y-m-d\TH:i:s'))->toBe('2026-03-20T16:00:00');
})->group('RF-ID-04');

it('el umbral es configurable y no una constante', function (): void {
    // Con 0,5 el dia 45 ya rota; con 0,8, no.
    $at = instanteDeRotacion('2026-02-15T00:00:00Z');

    expect(politicaDeRotacion(0.5)->decide([tokenDeNoventaDias(7)], 7, $at)->outcome)->toBe(DeviceTokenRotationOutcome::ROTATE)
        ->and(politicaDeRotacion(0.8)->decide([tokenDeNoventaDias(7)], 7, $at)->outcome)->toBe(DeviceTokenRotationOutcome::NONE);
})->group('RF-ID-04');

it('el solape nunca pasa de la caducidad propia del token relevado', function (): void {
    $decision = politicaDeRotacion()->decide([tokenDeNoventaDias(7)], 7, instanteDeRotacion('2026-03-31T20:00:00Z'));

    expect($decision->supersededUntil?->format('Y-m-d\TH:i:s'))->toBe('2026-04-01T00:00:00');
})->group('RF-ID-04');

it('un token en solape no abre otro solape: pide la reentrega del relevo sin tocar su fecha', function (): void {
    // El 7 ya fue relevado por el 9 (su caducidad se adelanto al 15 de marzo).
    // Que el 7 vuelva a firmar significa que el 9 no llego: se retira y se
    // emite otro, y el 7 conserva su fecha.
    $tokens = [
        tokenDeNoventaDias(7, expiresAt: '2026-03-15T00:00:00Z'),
        tokenDeNoventaDias(9, '2026-03-14T00:00:00Z', '2026-06-12T00:00:00Z'),
    ];

    $decision = politicaDeRotacion()->decide($tokens, 7, instanteDeRotacion('2026-03-14T12:00:00Z'));

    expect($decision->outcome)->toBe(DeviceTokenRotationOutcome::REDELIVER)
        ->and($decision->issuesToken())->toBeTrue()
        ->and($decision->shortensSuperseded())->toBeFalse()
        ->and($decision->retire)->toBe([9])
        ->and($decision->supersededTokenId)->toBe(7)
        ->and($decision->supersededUntil?->format('Y-m-d\TH:i:s'))->toBe('2026-03-15T00:00:00');
})->group('RF-ID-04', 'RS-04');

it('cuando firma el relevo, el token relevado se retira y no se emite nada', function (): void {
    $tokens = [
        tokenDeNoventaDias(7, expiresAt: '2026-03-15T00:00:00Z'),
        tokenDeNoventaDias(9, '2026-03-14T00:00:00Z', '2026-06-12T00:00:00Z'),
    ];

    $decision = politicaDeRotacion()->decide($tokens, 9, instanteDeRotacion('2026-03-14T12:00:00Z'));

    expect($decision->outcome)->toBe(DeviceTokenRotationOutcome::NONE)
        ->and($decision->retire)->toBe([7]);
})->group('RF-ID-04', 'RS-04');

it('el mas reciente es el de mayor identificador, no el ultimo de la lista', function (): void {
    $tokens = [
        tokenDeNoventaDias(9, '2026-03-14T00:00:00Z', '2026-06-12T00:00:00Z'),
        tokenDeNoventaDias(7, expiresAt: '2026-03-15T00:00:00Z'),
    ];

    expect(politicaDeRotacion()->decide($tokens, 7, instanteDeRotacion('2026-03-14T12:00:00Z'))->outcome)
        ->toBe(DeviceTokenRotationOutcome::REDELIVER)
        ->and(politicaDeRotacion()->decide($tokens, 9, instanteDeRotacion('2026-03-14T12:00:00Z'))->outcome)
        ->toBe(DeviceTokenRotationOutcome::NONE);
})->group('RF-ID-04');

it('no rota si el firmante ya no existe', function (): void {
    $decision = politicaDeRotacion()->decide([tokenDeNoventaDias(9)], 7, instanteDeRotacion('2026-03-20T00:00:00Z'));

    expect($decision->outcome)->toBe(DeviceTokenRotationOutcome::NONE)
        ->and($decision->retire)->toBe([]);
})->group('RF-ID-04');

it('no rota un token sin caducidad, ni vigente ni en solape', function (): void {
    $vigente = politicaDeRotacion()->decide([tokenDeNoventaDias(7, expiresAt: null)], 7, instanteDeRotacion('2027-01-01T00:00:00Z'));
    $relevado = politicaDeRotacion()->decide(
        [tokenDeNoventaDias(7, expiresAt: null), tokenDeNoventaDias(9)],
        7,
        instanteDeRotacion('2026-03-20T00:00:00Z'),
    );

    expect($vigente->outcome)->toBe(DeviceTokenRotationOutcome::NONE)
        ->and($relevado->outcome)->toBe(DeviceTokenRotationOutcome::NONE)
        ->and($relevado->retire)->toBe([]);
})->group('RF-ID-04');

it('no rota un token cuya caducidad no es posterior a su emision', function (): void {
    $decision = politicaDeRotacion()->decide(
        [tokenDeNoventaDias(7, '2026-03-01T00:00:00Z', '2026-03-01T00:00:00Z')],
        7,
        instanteDeRotacion('2026-03-01T00:00:00Z'),
    );

    expect($decision->outcome)->toBe(DeviceTokenRotationOutcome::NONE);
})->group('RF-ID-04');

it('rechaza un umbral o un solape fuera de rango', function (float $threshold, int $overlapSeconds): void {
    expect(static fn (): DeviceTokenRotationPolicy => new DeviceTokenRotationPolicy($threshold, $overlapSeconds))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'umbral cero' => [0.0, 3600],
    'umbral por encima de uno' => [1.01, 3600],
    'solape cero' => [0.8, 0],
    'solape negativo' => [0.8, -1],
])->group('RF-ID-04');

it('admite los extremos validos del umbral y del solape', function (): void {
    expect(new DeviceTokenRotationPolicy(1.0, 1))->toBeInstanceOf(DeviceTokenRotationPolicy::class);
})->group('RF-ID-04');

it('una rotacion siempre dice que token entra en solape y no puede retirarlo a la vez', function (): void {
    $until = new DateTimeImmutable('2026-03-15T00:00:00Z');

    expect(static fn (): DeviceTokenRotation => DeviceTokenRotation::rotate(7, $until, [7]))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeviceTokenRotation => DeviceTokenRotation::redeliver(7, $until, [7, 9]))
        ->toThrow(InvalidArgumentException::class)
        ->and(DeviceTokenRotation::rotate(7, $until, [3])->retire)->toBe([3]);
})->group('RF-ID-04');
