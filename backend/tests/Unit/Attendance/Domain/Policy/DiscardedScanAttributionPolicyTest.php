<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Policy\DiscardedScanAttributionPolicy;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use App\Modules\Shared\Domain\ValueObject\CredentialHolder;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/*
 * RN-22 (ADR-047): a quien se atribuye un aviso de fichaje descartado. Solo con
 * lo que un token de quiosco robado no puede fabricar: una tarjeta autentica, o
 * el codigo de alguien que puede fichar.
 */

const DISCARD_ATTRIBUTION_OWNER = '0199f0c2-0000-7000-8000-0000000000bb';

function instanteDeDescarte(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function duenoConEstado(EmploymentStatus $status): EmployeeSnapshot
{
    return new EmployeeSnapshot(
        employeeUuid: DISCARD_ATTRIBUTION_OWNER,
        employeeCode: 'E7QK2MXPR',
        displayName: 'Maria G.',
        status: $status,
        siteId: 1,
        hiredOn: '2026-01-01',
    );
}

it('atribuye la tarjeta vigente con su emision', function (): void {
    $attribution = (new DiscardedScanAttributionPolicy)->forCard(
        CredentialResolution::resolved(DISCARD_ATTRIBUTION_OWNER, instanteDeDescarte('2026-08-14 06:00:00')),
        instanteDeDescarte('2026-08-15 06:58:00'),
        instanteDeDescarte('2026-08-15 10:00:00'),
    );

    expect($attribution->method)->toBe(DiscardedScanAttributionMethod::CREDENTIAL)
        ->and($attribution->ownerUuid)->toBe(DISCARD_ATTRIBUTION_OWNER)
        ->and($attribution->credentialIssuedAt?->format('Y-m-d H:i:s'))->toBe('2026-08-14 06:00:00')
        ->and($attribution->isAttributed())->toBeTrue();
})->group('RN-22');

it('no atribuye una tarjeta resuelta sin emision conocida', function (): void {
    // F6: sin cota inferior no hay ventana, y sin ventana no se atribuye.
    $attribution = (new DiscardedScanAttributionPolicy)->forCard(
        CredentialResolution::resolved(DISCARD_ATTRIBUTION_OWNER),
        instanteDeDescarte('2026-08-15 06:58:00'),
        instanteDeDescarte('2026-08-15 10:00:00'),
    );

    expect($attribution->method)->toBe(DiscardedScanAttributionMethod::NONE);
})->group('RN-22');

it('atribuye la tarjeta retirada solo dentro de su vida, con la regla de RN-20', function (string $occurredAt, DiscardedScanAttributionMethod $expected): void {
    $holder = CredentialHolder::of(
        DISCARD_ATTRIBUTION_OWNER,
        instanteDeDescarte('2026-08-14 06:00:00'),
        instanteDeDescarte('2026-08-15 06:00:00'),
    );

    $attribution = (new DiscardedScanAttributionPolicy)->forCard(
        CredentialResolution::rejectedWithHolder(CredentialRejectionReason::REVOKED, $holder),
        instanteDeDescarte($occurredAt),
        instanteDeDescarte('2026-08-15 10:00:00'),
    );

    expect($attribution->method)->toBe($expected);
})->with([
    'antes de la retirada' => ['2026-08-15 05:59:59', DiscardedScanAttributionMethod::CREDENTIAL],
    'en la retirada' => ['2026-08-15 06:00:00', DiscardedScanAttributionMethod::NONE],
    'antes de la emision' => ['2026-08-14 05:59:59', DiscardedScanAttributionMethod::NONE],
])->group('RN-22', 'RN-20');

it('compara con la recepcion cuando la tarjeta seguia vigente y el titular esta de baja', function (): void {
    $holder = CredentialHolder::of(DISCARD_ATTRIBUTION_OWNER, instanteDeDescarte('2026-08-14 06:00:00'), null);
    $policy = new DiscardedScanAttributionPolicy;
    $resolution = CredentialResolution::rejectedWithHolder(CredentialRejectionReason::REVOKED, $holder);

    expect($policy->forCard($resolution, instanteDeDescarte('2026-08-15 09:59:59'), instanteDeDescarte('2026-08-15 10:00:00'))->method)
        ->toBe(DiscardedScanAttributionMethod::CREDENTIAL)
        ->and($policy->forCard($resolution, instanteDeDescarte('2026-08-15 10:00:01'), instanteDeDescarte('2026-08-15 10:00:00'))->method)
        ->toBe(DiscardedScanAttributionMethod::NONE);
})->group('RN-22', 'RN-20');

it('acota al dia del cese la tarjeta retirada, como el fichaje', function (string $occurredAt, ?string $employmentEndsAt, DiscardedScanAttributionMethod $expected): void {
    // ADR-062 §8, tarea 25.5: la misma cota que `WithdrawnCredentialPolicy` desde
    // la 25.2. Una baja programada se aplica despues de su fecha: entre medias
    // la tarjeta sigue «antes de su retirada», y sin la cota se atribuiria un
    // descarte del dia siguiente al cese. La retirada, el 20 a las 06:00 UTC.
    $holder = CredentialHolder::of(
        DISCARD_ATTRIBUTION_OWNER,
        instanteDeDescarte('2026-08-01 06:00:00'),
        instanteDeDescarte('2026-08-20 06:00:00'),
    );

    $attribution = (new DiscardedScanAttributionPolicy)->forCard(
        CredentialResolution::rejectedWithHolder(CredentialRejectionReason::REVOKED, $holder),
        instanteDeDescarte($occurredAt),
        instanteDeDescarte('2026-08-20 10:00:00'),
        $employmentEndsAt === null ? null : instanteDeDescarte($employmentEndsAt),
    );

    expect($attribution->method)->toBe($expected);
})->with([
    // Cese el 15 en Madrid: el dia acaba a las 22:00 UTC.
    'el ultimo segundo del dia del cese' => ['2026-08-15 21:59:59', '2026-08-15 22:00:00', DiscardedScanAttributionMethod::CREDENTIAL],
    'el primer segundo del dia siguiente' => ['2026-08-15 22:00:00', '2026-08-15 22:00:00', DiscardedScanAttributionMethod::NONE],
    'dos dias despues del cese, antes de la retirada' => ['2026-08-17 08:00:00', '2026-08-15 22:00:00', DiscardedScanAttributionMethod::NONE],
    'sin cese, antes de la retirada' => ['2026-08-17 08:00:00', null, DiscardedScanAttributionMethod::CREDENTIAL],
])->group('RN-22', 'RN-20', 'RN-14');

it('no acota con el cese una tarjeta vigente', function (): void {
    // La cota es de la tarjeta retirada; la vigente ya la decide su titular.
    $attribution = (new DiscardedScanAttributionPolicy)->forCard(
        CredentialResolution::resolved(DISCARD_ATTRIBUTION_OWNER, instanteDeDescarte('2026-08-01 06:00:00')),
        instanteDeDescarte('2026-08-17 08:00:00'),
        instanteDeDescarte('2026-08-20 10:00:00'),
        instanteDeDescarte('2026-08-15 22:00:00'),
    );

    expect($attribution->method)->toBe(DiscardedScanAttributionMethod::CREDENTIAL);
})->group('RN-22');

it('no atribuye una tarjeta que no es autentica', function (CredentialRejectionReason $reason): void {
    $attribution = (new DiscardedScanAttributionPolicy)->forCard(
        CredentialResolution::rejected($reason),
        instanteDeDescarte('2026-08-15 06:58:00'),
        instanteDeDescarte('2026-08-15 10:00:00'),
    );

    expect($attribution->method)->toBe(DiscardedScanAttributionMethod::NONE)
        ->and($attribution->ownerUuid)->toBeNull();
})->with([
    'firma invalida' => [CredentialRejectionReason::INVALID_SIGNATURE],
    'token desconocido' => [CredentialRejectionReason::UNKNOWN],
    'revocada sin titular' => [CredentialRejectionReason::REVOKED],
])->group('RN-22', 'RS-03');

it('atribuye por codigo solo a quien puede fichar', function (?EmploymentStatus $status, DiscardedScanAttributionMethod $expected): void {
    $owner = $status === null ? null : duenoConEstado($status);

    expect((new DiscardedScanAttributionPolicy)->forEmployeeCode($owner)->method)->toBe($expected);
})->with([
    'activo' => [EmploymentStatus::ACTIVE, DiscardedScanAttributionMethod::EMPLOYEE_CODE],
    'de baja' => [EmploymentStatus::TERMINATED, DiscardedScanAttributionMethod::NONE],
    'suspendido' => [EmploymentStatus::SUSPENDED, DiscardedScanAttributionMethod::NONE],
    'codigo inexistente' => [null, DiscardedScanAttributionMethod::NONE],
])->group('RN-25', 'RN-22', 'RN-19');
