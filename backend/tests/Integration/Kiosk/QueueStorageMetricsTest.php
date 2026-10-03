<?php

declare(strict_types=1);

use App\Modules\Kiosk\Infrastructure\Metrics\RedisKioskMetrics;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Str;

/*
 * F12 del dictamen de seguridad del bloque 18 (ADR-047), contra Redis real.
 *
 * `kiosk_offline_queue_size` es un `HSET` por quiosco y antes nunca se borraba:
 * con la cola en memoria y el tamaño desconocido, la serie se quedaba con el
 * ultimo valor —quiza un cero— y apagaba `ColaOfflineSinVaciar` justo cuando
 * habia fichajes que no se veian. Ahora un tamaño desconocido **retira** la
 * serie del quiosco, y `kiosk_queue_storage_degraded` se publica siempre, a uno
 * o a cero, para que baje en cuanto la tablet vuelve a disco.
 */

/** El valor del campo del quiosco, o `null` si la serie no lo tiene. */
function colaEnRedis(string $key, string $deviceUuid): ?int
{
    $value = app(RedisManager::class)->connection()->command('HGET', [$key, 'device='.$deviceUuid]);

    return is_numeric($value) ? (int) $value : null;
}

it('retira la serie de la cola con un tamano desconocido y la devuelve al volver a disco', function (): void {
    $metrics = app(RedisKioskMetrics::class);
    $device = Str::uuid7()->toString();

    $metrics->heartbeat($device, 1_789_560_000, 37);

    expect(colaEnRedis(RedisKioskMetrics::QUEUE_SIZE, $device))->toBe(37)
        ->and(colaEnRedis(RedisKioskMetrics::QUEUE_STORAGE_DEGRADED, $device))->toBe(0);

    $metrics->heartbeat($device, 1_789_560_060, null, queueStorageDegraded: true, unreportedDiscards: 2);

    expect(colaEnRedis(RedisKioskMetrics::QUEUE_SIZE, $device))->toBeNull()
        ->and(colaEnRedis(RedisKioskMetrics::QUEUE_STORAGE_DEGRADED, $device))->toBe(1)
        ->and(colaEnRedis(RedisKioskMetrics::UNREPORTED_DISCARDS, $device))->toBe(2);

    $metrics->heartbeat($device, 1_789_560_120, 0);

    expect(colaEnRedis(RedisKioskMetrics::QUEUE_SIZE, $device))->toBe(0)
        ->and(colaEnRedis(RedisKioskMetrics::QUEUE_STORAGE_DEGRADED, $device))->toBe(0)
        ->and(colaEnRedis(RedisKioskMetrics::UNREPORTED_DISCARDS, $device))->toBe(0);
})->group('RF-PA-07', 'RF-KI-04', 'RN-22');
