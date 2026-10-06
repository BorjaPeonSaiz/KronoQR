<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\RequestOrigin;

/*
 * Que es un origen para el bloqueo del portal (ADR-050 §2): IPv4 `/32`, IPv6
 * `/64` e IPv4 mapeada normalizada a IPv4.
 */

it('normaliza cada direccion a su origen', function (string $direccion, string $origen): void {
    expect(RequestOrigin::of($direccion)->key())->toBe($origen);
})->with([
    'IPv4 es su /32' => ['203.0.113.7', '203.0.113.7'],
    'IPv4 con espacios' => [' 203.0.113.7 ', '203.0.113.7'],
    'IPv6 es su /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'],
    'IPv6 corta' => ['2001:db8::1', '2001:db8::/64'],
    'IPv4 mapeada es IPv4' => ['::ffff:203.0.113.7', '203.0.113.7'],
    'IPv6 en mayusculas' => ['2001:DB8:1:2::1', '2001:db8:1:2::/64'],
])->group('RS-12');

it('da el mismo origen a dos direcciones del mismo /64', function (): void {
    expect(RequestOrigin::of('2001:db8:1:2::1')->equals(RequestOrigin::of('2001:db8:1:2:ffff::9')))->toBeTrue()
        ->and(RequestOrigin::of('2001:db8:1:2::1')->equals(RequestOrigin::of('2001:db8:1:3::1')))->toBeFalse();
})->group('RS-12');

it('rechaza lo que no es una direccion', function (string $basura): void {
    expect(static fn (): RequestOrigin => RequestOrigin::of($basura))->toThrow(InvalidArgumentException::class);
})->with(['', 'localhost', '203.0.113', '203.0.113.256', '2001:db8::/64', 'unknown'])->group('RS-12');

it('lleva una direccion ausente o ilegible al origen comun, nunca fuera de la cuenta', function (?string $direccion): void {
    expect(RequestOrigin::fromRemoteAddress($direccion)->equals(RequestOrigin::unknown()))->toBeTrue();
})->with([null, '', 'no-es-ip'])->group('RS-12');
