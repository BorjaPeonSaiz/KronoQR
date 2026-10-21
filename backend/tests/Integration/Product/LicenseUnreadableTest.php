<?php

declare(strict_types=1);

use App\Modules\Product\Application\Exception\LicenseUnavailable;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Infrastructure\Persistence\DatabaseLicenseRepository;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Support\Database\DatabaseUnavailable;
use App\Support\Health\LicenseStateProbe;
use App\Support\Resilience\DependencyFailure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;

/*
 * LA TABLA `license` QUE NO SE PUEDE LEER PORQUE POSTGRESQL NO RESPONDE NO ES
 * «SIN LICENCIA» (V21-4-PL-1), Y CUALQUIER OTRO FALLO AL LEERLA NO ES `unknown`
 * (ADR-059 §4, revision de seguridad del bloque 10).
 *
 * - Con PostgreSQL caido (circuito abierto o conexion perdida): `current()`
 *   lanza `LicenseUnavailable` en lugar de devolver `null`, `license:show` no
 *   dice «SIN LICENCIA» ni manda a activarla, y la cache de la sonda de salud no
 *   recibe `absent`.
 * - Con PostgreSQL respondiendo y la tabla ilegible —un `REVOKE`, un `RENAME`—
 *   es `null` y el gate **deniega** lo accesorio: `unknown` lo concede todo, y
 *   aceptarlo aqui seria un salto de licencia con una sola sentencia SQL.
 * - Una tabla sin filas sigue siendo `null`: ahi si se sabe que no hay.
 */

uses(RefreshDatabase::class);

/**
 * La clasificacion que el proveedor del modulo le da al repositorio. La prueba
 * del `REVOKE`, mas abajo, pasa por el enlace real del contenedor.
 */
function licenseUnreadableOutage(Throwable $fallo): bool
{
    return DependencyFailure::dependencyOf($fallo) === DependencyFailure::DATABASE;
}

/**
 * Un repositorio sobre una conexion cuya consulta a `license` siempre falla
 * con `$fallo`.
 */
function licenseUnreadableRepository(?Throwable $fallo = null): DatabaseLicenseRepository
{
    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('select')->andThrow($fallo ?? new QueryException('pgsql', 'SELECT 1 FROM license', [], DatabaseUnavailable::unreachable(10)));

    return new DatabaseLicenseRepository($connection, new NullLogger, licenseUnreadableOutage(...));
}

it('lanza LicenseUnavailable si PostgreSQL no responde y devuelve null si no hay filas', function (): void {
    expect(static fn () => licenseUnreadableRepository()->current())->toThrow(LicenseUnavailable::class)
        ->and((new DatabaseLicenseRepository(DB::connection(), new NullLogger, licenseUnreadableOutage(...)))->current())->toBeNull();
})->group('RF-PD-04', 'RNF-D-03');

it('no convierte en unknown un fallo que no es de PostgreSQL caido', function (Throwable $fallo): void {
    expect(licenseUnreadableRepository($fallo)->current())->toBeNull();
})->with([
    'permiso retirado' => [fn (): Throwable => new QueryException('pgsql', 'SELECT 1 FROM license', [], new PDOException('SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for table license', 42501))],
    'tabla renombrada' => [fn (): Throwable => new QueryException('pgsql', 'SELECT 1 FROM license', [], new PDOException('SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "license" does not exist'))],
    'cualquier otra cosa' => [fn (): Throwable => new RuntimeException('no connection to the server')],
])->group('RF-PD-04', 'RS-12');

it('con SELECT retirado al rol de la aplicacion, el gate deniega lo accesorio', function (): void {
    // La base de verdad, con una licencia vigente que lo concede todo: el
    // propietario de la tabla retira el permiso al rol con el que corre la
    // aplicacion, que es el de la conexion por defecto. Con `unknown` el gate
    // lo concederia; tiene que denegarlo.
    LicenseKeys::grantAll();
    expect(app(FeatureGate::class)->statusOf(Feature::AdvancedReports)->enabled)->toBeTrue();

    $propietario = DB::connection('pgsql_migrator');
    $rol = DB::scalar('SELECT current_user');
    assert(is_string($rol));

    $propietario->statement('REVOKE SELECT ON license FROM '.$rol);

    try {
        app()->forgetInstance(FeatureGate::class);
        $estado = app(FeatureGate::class)->statusOf(Feature::AdvancedReports);

        expect(static fn () => app(LicenseRepository::class)->current())->not->toThrow(LicenseUnavailable::class)
            ->and($estado->enabled)->toBeFalse();
    } finally {
        $propietario->statement('GRANT SELECT ON license TO '.$rol);
    }
})->group('RF-PD-04', 'RS-12');

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
