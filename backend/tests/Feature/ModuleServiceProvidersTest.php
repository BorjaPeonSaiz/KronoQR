<?php

declare(strict_types=1);

use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Compliance\ComplianceServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Kiosk\KioskServiceProvider;
use App\Modules\Product\ProductServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Infrastructure\Adapter\SystemClock;
use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Workforce\WorkforceServiceProvider;

/*
 * Los ocho modulos del doc 02 §1.6 estan registrados y el unico puerto que la
 * tarea 0.2 declara resuelve a su unico adaptador (ADR-021).
 *
 * Que un provider exista como fichero no significa que se cargue: estas pruebas
 * comprueban lo segundo, que es lo que se rompe en silencio al anadir un modulo
 * y olvidar bootstrap/providers.php.
 */

it('carga el proveedor de servicios del modulo', function (string $provider): void {
    $loaded = app()->getProviders($provider);

    expect($loaded)->not->toBeEmpty();
})->with([
    'Shared' => SharedServiceProvider::class,
    'Attendance' => AttendanceServiceProvider::class,
    'Compliance' => ComplianceServiceProvider::class,
    'Workforce' => WorkforceServiceProvider::class,
    'Identity' => IdentityServiceProvider::class,
    'Reporting' => ReportingServiceProvider::class,
    'Kiosk' => KioskServiceProvider::class,
    'Product' => ProductServiceProvider::class,
])->group('RNF-M-03');

it('resuelve el puerto Clock al adaptador SystemClock', function (): void {
    $clock = app(Clock::class);

    expect($clock)->toBeInstanceOf(SystemClock::class);
})->group('RNF-M-03');

it('sirve siempre la misma instancia del reloj', function (): void {
    $first = app(Clock::class);
    $second = app(Clock::class);

    expect($first)->toBe($second);
})->group('RNF-M-03');

it('arranca la aplicacion con la zona horaria en UTC', function (): void {
    // Regla dura 3. Si esto cambia, el registro legal deja de ser valido.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
})->group('RNF-M-03');
