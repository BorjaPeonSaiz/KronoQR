<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Infrastructure\Adapter\SystemClock;

/*
 * El adaptador del puerto Clock (ADR-021). Suite Unit: sin base de datos y sin
 * arrancar el framework.
 */

it('implementa el puerto Clock que declara Shared', function (): void {
    $clock = new SystemClock;

    expect($clock)->toBeInstanceOf(Clock::class);
})->group('RNF-M-03');

it('devuelve el instante en UTC', function (): void {
    $clock = new SystemClock;

    $instant = $clock->now();

    // Regla dura 3: todo instante se almacena en UTC. Si el adaptador devolviera
    // la zona del proceso, la jornada se atribuiria a la fecha civil equivocada
    // en cuanto alguien tocase APP_TIMEZONE.
    expect($instant->getTimezone()->getName())->toBe('UTC')
        ->and($instant->getOffset())->toBe(0);
})->group('RNF-M-03');

it('devuelve el instante actual aunque la zona del proceso no sea UTC', function (): void {
    $processTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Madrid');

    try {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $instant = (new SystemClock)->now();
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } finally {
        date_default_timezone_set($processTimezone);
    }

    expect($instant->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
        ->and($instant->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
})->group('RNF-M-03');
