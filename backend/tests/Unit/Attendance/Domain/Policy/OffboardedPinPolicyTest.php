<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Policy\OffboardedPinPolicy;
use App\Modules\Shared\Domain\ValueObject\OffboardedPinHolder;

/*
 * RN-20, D15 (enmienda del 29-10-2026 de ADR-043; ADR-062 §8): el PIN correcto
 * de una persona de baja solo se atribuye si la fecha civil del `occurred_at`,
 * en la zona del centro, cae entre el alta y el cese del periodo que cerro la
 * baja, los dos incluidos. Fechas civiles y no instantes: los bordes se miran
 * en la zona del centro, tambien el dia del cambio de hora.
 */

const OFFBOARDED_PIN_POLICY_UUID = '0199f0c2-0000-7000-8000-0000000000cc';

function bajaConPeriodo(string $hiredOn, string $terminatedOn, string $zone = 'Europe/Madrid'): OffboardedPinHolder
{
    return OffboardedPinHolder::of(OFFBOARDED_PIN_POLICY_UUID, $hiredOn, $terminatedOn, new DateTimeZone($zone));
}

function pinTecleadoEl(string $instant, string $zone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone($zone));
}

it('atribuye solo dentro del periodo, con los dos extremos incluidos y en la zona del centro', function (string $occurredAtUtc, bool $expected): void {
    // Alta el 1 de marzo, cese el 15 de agosto, en Madrid (UTC+1 en invierno,
    // UTC+2 en verano).
    $holder = bajaConPeriodo('2026-03-01', '2026-08-15');

    expect((new OffboardedPinPolicy)->attributes(pinTecleadoEl($occurredAtUtc), $holder))->toBe($expected);
})->with([
    'la salida del ultimo dia, sin red' => ['2026-08-15 13:00:00', true],
    'el ultimo segundo del dia del cese en Madrid' => ['2026-08-15 21:59:59', true],
    'el primer segundo del dia siguiente en Madrid' => ['2026-08-15 22:00:00', false],
    'una fecha posterior al cese' => ['2026-09-01 08:00:00', false],
    'el primer segundo del dia del alta en Madrid' => ['2026-02-28 23:00:00', true],
    'el ultimo segundo de la vispera del alta en Madrid' => ['2026-02-28 22:59:59', false],
    'una fecha elegida de hace años' => ['2019-01-01 00:00:00', false],
])->group('RN-20', 'RN-14', 'RN-27');

it('lee la fecha civil en la zona del centro el dia del cambio de hora', function (): void {
    // 25-10-2026: Madrid pasa de UTC+2 a UTC+1 a las 03:00 locales. Con cese ese
    // dia, las 22:59:59 UTC son todavia el 25 en Madrid y las 23:00:00 ya el 26.
    $holder = bajaConPeriodo('2026-01-01', '2026-10-25');
    $policy = new OffboardedPinPolicy;

    expect($policy->attributes(pinTecleadoEl('2026-10-25 22:59:59'), $holder))->toBeTrue()
        ->and($policy->attributes(pinTecleadoEl('2026-10-25 23:00:00'), $holder))->toBeFalse();
})->group('RN-20', 'RN-09');

it('no depende de la zona con la que llegue el instante', function (): void {
    // El mismo instante expresado en otra zona: la fecha la decide la del centro.
    $holder = bajaConPeriodo('2026-03-01', '2026-08-15', 'America/New_York');
    $policy = new OffboardedPinPolicy;

    // 2026-08-16 02:00 UTC son las 22:00 del 15 en Nueva York.
    expect($policy->attributes(pinTecleadoEl('2026-08-16 04:00:00', 'Europe/Madrid'), $holder))->toBeTrue()
        ->and($policy->attributes(pinTecleadoEl('2026-08-16 04:00:01', 'UTC'), $holder))->toBeFalse();
})->group('RN-20');

it('admite un periodo de un solo dia', function (): void {
    $holder = bajaConPeriodo('2026-08-15', '2026-08-15');
    $policy = new OffboardedPinPolicy;

    expect($policy->attributes(pinTecleadoEl('2026-08-15 10:00:00'), $holder))->toBeTrue()
        ->and($policy->attributes(pinTecleadoEl('2026-08-14 10:00:00'), $holder))->toBeFalse()
        ->and($policy->attributes(pinTecleadoEl('2026-08-16 10:00:00'), $holder))->toBeFalse();
})->group('RN-20', 'RN-14');
