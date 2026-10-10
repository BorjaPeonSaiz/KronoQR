<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;

/*
 * El «latido reciente» de un quiosco (**RF-KI-07**, RF-PA-07; bloque 1 de la
 * 2.2.1): el criterio unico con el que la sonda `kiosk.app_version` elige que
 * tablets mirar, el mismo plazo de silencio que el veredicto `silent` de
 * `KioskHealthRow`. Se fija al segundo porque la mutacion vive de fronteras.
 */

function instanteDelLatidoReciente(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-16 12:00:00', new DateTimeZone('UTC'));
}

function quioscoLatiendoHace(?int $seconds, string $status = 'active'): DeviceSummary
{
    return new DeviceSummary(
        id: 1,
        uuid: '0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01',
        name: 'Recepcion',
        status: $status,
        appVersion: '2.2.0',
        lastSeenAt: $seconds === null ? null : instanteDelLatidoReciente()->modify(sprintf('%+d seconds', -$seconds)),
        pendingQueueSize: 0,
        pairedAt: null,
    );
}

it('cuenta como latido reciente hasta el plazo de silencio exacto, ni un segundo mas', function (
    ?int $seconds,
    string $status,
    bool $recent,
): void {
    $thresholds = new KioskHealthThresholds(freshWithinSeconds: 120, silentAfterSeconds: 600);

    expect(quioscoLatiendoHace($seconds, $status)->isBeatingRecently(instanteDelLatidoReciente(), $thresholds))
        ->toBe($recent);
})->with([
    'ahora mismo' => [0, 'active', true],
    'en el plazo exacto' => [600, 'active', true],
    'un segundo despues' => [601, 'active', false],
    'nunca latio' => [null, 'active', false],
    'revocado y latiendo' => [10, 'revoked', false],
    // Un `last_seen_at` en el futuro (restauracion, NTP) no es «callado».
    'reloj por delante' => [-30, 'active', true],
])->group('RF-KI-07', 'RF-PA-07');

it('mide los segundos desde el ultimo latido sin bajar de cero', function (): void {
    expect(quioscoLatiendoHace(42)->secondsSinceLastSeen(instanteDelLatidoReciente()))->toBe(42)
        ->and(quioscoLatiendoHace(-30)->secondsSinceLastSeen(instanteDelLatidoReciente()))->toBe(0)
        ->and(quioscoLatiendoHace(null)->secondsSinceLastSeen(instanteDelLatidoReciente()))->toBeNull()
        ->and(quioscoLatiendoHace(0)->isActive())->toBeTrue()
        ->and(quioscoLatiendoHace(0, 'revoked')->isActive())->toBeFalse();
})->group('RF-KI-07', 'RF-PA-07');

it('da por callado solo lo que pasa del plazo de silencio', function (): void {
    $thresholds = new KioskHealthThresholds(freshWithinSeconds: 120, silentAfterSeconds: 600);

    expect($thresholds->isSilentAfter(600))->toBeFalse()
        ->and($thresholds->isSilentAfter(601))->toBeTrue();
})->group('RF-KI-07', 'RF-PA-07');
