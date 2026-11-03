<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\TlsProbe;
use Tests\Support\Time\FixedClock;

/*
 * `tls.certificate` con un `APP_URL` que no se puede interpretar dice su
 * ESTADO en `app_url_state`, no la direccion (revision de la 33.8; RL-19,
 * RF-PD-09). `app_url` es una clave de host en el filtro del paquete: si la
 * sonda la usara para el estado, el paquete diria `[app_host]` en vez de
 * `unparseable`.
 */

it('con APP_URL ilegible avisa y dice el estado en app_url_state, sin la direccion', function (): void {
    $hallazgos = (new TlsProbe(
        applicationUrl: 'fichaje.hotel-ejemplo-sol',
        allowSelfSigned: false,
        clock: FixedClock::at('2026-11-02 09:00:00'),
        resolves: static fn (string $host): bool => true,
    ))->run();

    expect($hallazgos)->toHaveCount(1)
        ->and($hallazgos[0]->status)->toBe(DoctorStatus::Warning)
        ->and($hallazgos[0]->variant)->toBe('no_url')
        ->and($hallazgos[0]->details)->toBe(['app_url_state' => 'unparseable'])
        ->and($hallazgos[0]->params)->toBe([]);
})->group('RL-19', 'RF-PD-09', 'RF-PD-13');
