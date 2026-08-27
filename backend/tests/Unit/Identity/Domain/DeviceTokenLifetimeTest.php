<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\DeviceTokenLifetime;

/*
 * La vida del token de quiosco y su rotacion al 80 % (RF-ID-04, doc 02 §7.3).
 *
 * Sin reloj dentro (regla dura 2): el instante entra como parametro. Sin eso no
 * se podria comprobar el limite exacto sin esperar 72 dias.
 */

function ninetyDayToken(float $threshold = 0.8): DeviceTokenLifetime
{
    return new DeviceTokenLifetime(
        issuedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        expiresAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'), // 90 dias
        rotationThreshold: $threshold,
    );
}

it('rota al 80 % de la vida, que con 90 dias es el dia 72', function (): void {
    // §7.3. Renovarlo el ultimo dia dejaria sin fichar a una tablet que hubiera
    // pasado una semana desconectada (regla dura 19).
    $lifetime = ninetyDayToken();

    expect($lifetime->rotationDueAt()->format('Y-m-d'))->toBe('2026-03-14');
})->group('RF-ID-04');

it('no rota antes de tiempo', function (): void {
    $lifetime = ninetyDayToken();

    expect($lifetime->isRotationDue(new DateTimeImmutable('2026-03-13T23:59:59+00:00')))->toBeFalse()
        ->and($lifetime->isRotationDue(new DateTimeImmutable('2026-01-01T00:00:01+00:00')))->toBeFalse();
})->group('RF-ID-04');

it('rota en el instante exacto del umbral y despues', function (): void {
    $lifetime = ninetyDayToken();

    expect($lifetime->isRotationDue(new DateTimeImmutable('2026-03-14T00:00:00+00:00')))->toBeTrue()
        ->and($lifetime->isRotationDue(new DateTimeImmutable('2026-03-31T00:00:00+00:00')))->toBeTrue();
})->group('RF-ID-04');

it('deja 18 dias de margen entre la rotacion y la caducidad', function (): void {
    // El margen es el requisito, no un efecto: hace falta que un quiosco este
    // mas de dos semanas incomunicado para que su token muera, y para entonces
    // la alerta de latido ya sono.
    $lifetime = ninetyDayToken();

    $margen = $lifetime->expiresAt->getTimestamp() - $lifetime->rotationDueAt()->getTimestamp();

    expect((int) ($margen / 86400))->toBe(18);
})->group('RF-ID-04');

it('sabe cuando el token ya no vale', function (): void {
    $lifetime = ninetyDayToken();

    expect($lifetime->hasExpired(new DateTimeImmutable('2026-03-31T23:59:59+00:00')))->toBeFalse()
        ->and($lifetime->hasExpired(new DateTimeImmutable('2026-04-01T00:00:00+00:00')))->toBeTrue();
})->group('RF-ID-04');

it('rechaza una vida imposible o un umbral fuera de rango', function (): void {
    $issued = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

    expect(static fn (): DeviceTokenLifetime => new DeviceTokenLifetime($issued, $issued, 0.8))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeviceTokenLifetime => new DeviceTokenLifetime($issued, $issued->modify('+1 day'), 0.0))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeviceTokenLifetime => new DeviceTokenLifetime($issued, $issued->modify('+1 day'), 1.5))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-ID-04');
