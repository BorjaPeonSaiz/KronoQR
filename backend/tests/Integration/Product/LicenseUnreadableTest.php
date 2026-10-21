<?php

declare(strict_types=1);

use App\Modules\Product\Application\Exception\LicenseUnavailable;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabaseLicenseRepository;
use App\Support\Health\LicenseStateProbe;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\Support\Database\RefreshDatabase;

/*
 * LA TABLA `license` QUE NO SE PUEDE LEER NO ES «SIN LICENCIA» (V21-4-PL-1).
 *
 * Con la base de verdad, sin la tabla: `current()` lanza `LicenseUnavailable`
 * en lugar de devolver `null`, `license:show` no dice «SIN LICENCIA» ni manda a
 * activarla, y la cache de la sonda de salud no recibe `absent`. Una tabla sin
 * filas sigue siendo `null`: ahi si se sabe que no hay.
 */

uses(RefreshDatabase::class);

/**
 * Un repositorio sobre una conexion cuya consulta a `license` siempre falla,
 * como una base caida a mitad de la peticion.
 */
function licenseUnreadableRepository(): DatabaseLicenseRepository
{
    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('select')->andThrow(new RuntimeException('SQLSTATE[08006] could not connect to server'));

    return new DatabaseLicenseRepository($connection, new NullLogger);
}

it('lanza LicenseUnavailable si no puede leer y devuelve null si no hay filas', function (): void {
    expect(static fn () => licenseUnreadableRepository()->current())->toThrow(LicenseUnavailable::class)
        ->and((new DatabaseLicenseRepository(DB::connection(), new NullLogger))->current())->toBeNull();
})->group('RF-PD-04', 'RNF-D-03');

it('license:show dice DESCONOCIDO, no SIN LICENCIA, y no manda a activarla', function (): void {
    app()->instance(LicenseRepository::class, licenseUnreadableRepository());
    Cache::put(LicenseStateProbe::CACHE_KEY, 'valid', 60);

    $code = Artisan::call('license:show');
    $output = Artisan::output();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('DESCONOCIDO')
        ->and($output)->not->toContain('SIN LICENCIA')
        ->and($output)->not->toContain('license:activate')
        // Lo ultimo que se supo se queda: nadie publica `absent`.
        ->and(Cache::get(LicenseStateProbe::CACHE_KEY))->toBe('valid');
})->group('RF-PD-04', 'RF-PD-09', 'RNF-D-03');
