<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Policy\DiscardedScanReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;

/*
 * RN-22 y F6 del dictamen del bloque 18: el aviso se guarda siempre, pero la
 * incidencia `discarded_scan` solo se abre con un `occurred_at` creible —ni
 * posterior a la recepcion mas el desfase admitido, ni anterior a que la
 * tarjeta o el alta existieran, ni mas antiguo que la ventana de revision—.
 */

const DISCARD_REVIEW_RECORDED = '2026-08-15 10:00:00';

function avisoRevisable(
    string $occurredAt,
    DiscardedScanAttributionMethod $attribution = DiscardedScanAttributionMethod::EMPLOYEE_CODE,
    ?string $problemType = null,
): DiscardedScan {
    $utc = new DateTimeZone('UTC');

    return new DiscardedScan(
        scanId: '0199f0c2-0000-7000-8000-0000000000cc',
        ownerUuid: '0199f0c2-0000-7000-8000-0000000000bb',
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000dd',
        origin: $attribution === DiscardedScanAttributionMethod::CREDENTIAL ? ScanOrigin::QR_KIOSK : ScanOrigin::PIN_KIOSK,
        occurredAt: new DateTimeImmutable($occurredAt, $utc),
        recordedAt: new DateTimeImmutable(DISCARD_REVIEW_RECORDED, $utc),
        httpStatus: 400,
        problemType: $problemType,
        attribution: $attribution,
        credentialIssuedAt: $attribution === DiscardedScanAttributionMethod::CREDENTIAL
            ? new DateTimeImmutable('2026-08-14 06:00:00', $utc)
            : null,
        ownerHiredOn: '2026-08-01',
    );
}

it('abre solo dentro de la ventana creible', function (string $occurredAt, DiscardedScanAttributionMethod $attribution, bool $expected): void {
    // 15 min de desfase admitido y 31 dias de ventana; alta el 2026-08-01 en
    // Madrid (22:00 UTC del dia 31 de julio).
    $policy = new DiscardedScanReviewPolicy(900, 31);

    expect($policy->opensIncident(avisoRevisable($occurredAt, $attribution), new DateTimeZone('Europe/Madrid')))->toBe($expected);
})->with([
    'una hora antes de la recepcion' => ['2026-08-15 09:00:00', DiscardedScanAttributionMethod::EMPLOYEE_CODE, true],
    'en el borde del desfase' => ['2026-08-15 10:15:00', DiscardedScanAttributionMethod::EMPLOYEE_CODE, true],
    'un segundo pasado el desfase' => ['2026-08-15 10:15:01', DiscardedScanAttributionMethod::EMPLOYEE_CODE, false],
    'en el inicio del dia del alta' => ['2026-07-31 22:00:00', DiscardedScanAttributionMethod::EMPLOYEE_CODE, true],
    'un segundo antes del alta' => ['2026-07-31 21:59:59', DiscardedScanAttributionMethod::EMPLOYEE_CODE, false],
    'en la emision de la tarjeta' => ['2026-08-14 06:00:00', DiscardedScanAttributionMethod::CREDENTIAL, true],
    'un segundo antes de la emision' => ['2026-08-14 05:59:59', DiscardedScanAttributionMethod::CREDENTIAL, false],
])->group('RN-22', 'RS-03');

it('no abre nada mas antiguo que la ventana de revision', function (): void {
    $policy = new DiscardedScanReviewPolicy(900, 3);

    expect($policy->opensIncident(avisoRevisable('2026-08-12 10:00:00'), new DateTimeZone('UTC')))->toBeTrue()
        ->and($policy->opensIncident(avisoRevisable('2026-08-12 09:59:59'), new DateTimeZone('UTC')))->toBeFalse();
})->group('RN-22');

it('no admite umbrales sin sentido', function (): void {
    expect(fn (): DiscardedScanReviewPolicy => new DiscardedScanReviewPolicy(-1, 31))->toThrow(InvalidArgumentException::class)
        ->and(fn (): DiscardedScanReviewPolicy => new DiscardedScanReviewPolicy(900, 0))->toThrow(InvalidArgumentException::class);
})->group('RN-22');

it('admite cero segundos de desfase y una ventana de un dia, en sus bordes exactos', function (): void {
    // Los limites inferiores validos: sin tolerancia, nada posterior a la
    // recepcion abre; con un dia de ventana, justo un dia antes si abre.
    $policy = new DiscardedScanReviewPolicy(0, 1);
    $utc = new DateTimeZone('UTC');

    expect($policy->opensIncident(avisoRevisable(DISCARD_REVIEW_RECORDED), $utc))->toBeTrue()
        ->and($policy->opensIncident(avisoRevisable('2026-08-15 10:00:01'), $utc))->toBeFalse()
        ->and($policy->opensIncident(avisoRevisable('2026-08-14 10:00:00'), $utc))->toBeTrue()
        ->and($policy->opensIncident(avisoRevisable('2026-08-14 09:59:59'), $utc))->toBeFalse();
})->group('RN-22');

it('acota por la emision de la tarjeta y no por el alta cuando atribuye la tarjeta', function (): void {
    // La emision (2026-08-14 06:00) es posterior al alta (2026-08-01): un aviso
    // del 2026-08-10 cae despues del alta pero antes de la emision.
    $policy = new DiscardedScanReviewPolicy(900, 31);
    $utc = new DateTimeZone('UTC');

    expect($policy->opensIncident(avisoRevisable('2026-08-10 12:00:00', DiscardedScanAttributionMethod::CREDENTIAL), $utc))->toBeFalse()
        ->and($policy->opensIncident(avisoRevisable('2026-08-10 12:00:00', DiscardedScanAttributionMethod::EMPLOYEE_CODE), $utc))->toBeTrue();
})->group('RN-22');

it('reduce el problema recibido a un catalogo cerrado de este producto', function (?string $type, string $expected): void {
    // F9: el `context` de la incidencia solo admite cadenas cortas y nunca el
    // texto libre que trajo la tablet.
    expect(avisoRevisable('2026-08-15 09:00:00', problemType: $type)->problem())->toBe($expected);
})->with([
    'sin problema' => [null, ''],
    'del catalogo' => ['urn:kronoqr:problem:invalid-request', 'invalid-request'],
    'de validacion' => ['urn:kronoqr:problem:validation-failed', 'validation-failed'],
    'del producto pero desconocido' => ['urn:kronoqr:problem:'.str_repeat('a', 150), 'other'],
    'ajeno al producto' => ['urn:otro:problem:invalid-request', 'other'],
])->group('RN-22');
