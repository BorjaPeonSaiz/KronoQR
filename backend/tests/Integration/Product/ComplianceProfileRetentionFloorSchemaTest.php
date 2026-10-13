<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\RetentionYearsFloor;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El suelo legal de la retencion en el esquema de `compliance_profiles` (RL-02,
 * RF-PD-07, ADR-057 enmienda del 10-10-2026, migracion
 * 2026_10_10_100000_add_retention_floor_checks_to_compliance_profiles).
 *
 * POR QUE INTEGRACION: lo que se prueba son dos `CHECK` de PostgreSQL —la red
 * de quien edita la fila a mano— y que digan lo mismo que el catalogo de
 * `config/compliance.php`, que es lo que lee PHP. Son dos copias del mismo dato
 * legal, a proposito; esta prueba es lo que impide que se separen.
 */

uses(RefreshDatabase::class);

/**
 * Cambia el perfil de serie en un punto de guardado y devuelve el error de
 * PostgreSQL, o `null` si lo admitio. El punto de guardado deshace el cambio en
 * los dos casos: un error dentro de la transaccion de `RefreshDatabase` la
 * dejaria abortada para las sentencias siguientes.
 *
 * @param  array<string, mixed>  $values
 */
function retentionFloorSchemaUpdate(array $values): ?QueryException
{
    try {
        DB::transaction(static function () use ($values): void {
            DB::table('compliance_profiles')->where('name', 'ES-hosteleria')->update($values);

            throw new RuntimeException('deshacer');
        });
    } catch (QueryException $rejected) {
        return $rejected;
    } catch (RuntimeException) {
        // El `deshacer` de arriba: la fila paso y la transaccion se revirtio.
        return null;
    }
}

it('rechaza un perfil español por debajo de cuatro años, tambien en una subdivision', function (string $jurisdiction): void {
    $rejected = retentionFloorSchemaUpdate(['jurisdiction' => $jurisdiction, 'retention_years' => 3]);

    expect($rejected?->getCode())->toBe('23514')
        ->and($rejected?->getMessage())->toContain('compliance_profiles_chk_retention_floor_es');
})->with(['ES', 'ES-CN'])->group('RF-PD-07', 'RL-02');

it('admite el suelo exacto en España y un año fuera del catalogo', function (): void {
    expect(retentionFloorSchemaUpdate(['jurisdiction' => 'ES', 'retention_years' => 4]))->toBeNull()
        ->and(retentionFloorSchemaUpdate(['jurisdiction' => 'ES-CN', 'retention_years' => 4]))->toBeNull()
        ->and(retentionFloorSchemaUpdate(['jurisdiction' => 'PT', 'retention_years' => 1]))->toBeNull();
})->group('RF-PD-07', 'RL-02');

it('rechaza una jurisdiccion sin forma ISO 3166, que esquivaria el suelo español', function (string $jurisdiction): void {
    $rejected = retentionFloorSchemaUpdate(['jurisdiction' => $jurisdiction, 'retention_years' => 1]);

    expect($rejected?->getCode())->toBe('23514')
        ->and($rejected?->getMessage())->toContain('compliance_profiles_chk_jurisdiction_format');
})->with(['es', 'ESP', ' ES', 'ES-', 'ES-ABCD', 'E'])->group('RF-PD-07', 'RL-02');

it('dice lo mismo en el catalogo y en el esquema', function (): void {
    // Cada pais del catalogo con suelo por encima del de omision tiene su
    // CHECK: un año menos lo rechaza PostgreSQL y el suelo exacto pasa. Añadir
    // un pais a `retention_floors` sin su migracion pone esto en rojo.
    $default = config()->integer('compliance.retention_floors.default');
    /** @var array<string, int> $jurisdictions */
    $jurisdictions = config()->array('compliance.retention_floors.jurisdictions');

    expect($jurisdictions)->not->toBe([]);

    foreach ($jurisdictions as $country => $floor) {
        if ($floor <= $default) {
            continue;
        }

        $rejected = retentionFloorSchemaUpdate(['jurisdiction' => $country, 'retention_years' => $floor - 1]);

        expect($rejected?->getCode())->toBe('23514', $country.': el esquema admite '.($floor - 1).' años')
            ->and($rejected?->getMessage())->toContain('compliance_profiles_chk_retention_floor_'.strtolower($country))
            ->and(retentionFloorSchemaUpdate(['jurisdiction' => $country, 'retention_years' => $floor]))->toBeNull();
    }

    // Un pais fuera del catalogo admite el de omision y rechaza uno menos (el
    // CHECK de positivos, que es el mismo minimo).
    expect(retentionFloorSchemaUpdate(['jurisdiction' => 'ZZ', 'retention_years' => $default]))->toBeNull()
        ->and(retentionFloorSchemaUpdate(['jurisdiction' => 'ZZ', 'retention_years' => $default - 1])?->getCode())->toBe('23514');
})->group('RF-PD-07', 'RL-02');

it('resuelve el suelo del centro por su perfil asignado o, si no tiene, por el de omision', function (): void {
    // CatalogRetentionYearsFloor::forSite, con la misma cascada que los umbrales.
    $site = WorkforceFixtures::site();
    $assigned = DB::table('compliance_profiles')->insertGetId([
        'name' => 'Convenio portugues',
        'jurisdiction' => 'PT',
        'retention_years' => 5,
        'min_rest_hours' => 11,
        'max_daily_hours' => 8,
        'max_weekly_hours' => 40,
        'break_required_after_hours' => 6,
        'week_starts_on' => 1,
        'holiday_calendar' => '[]',
        'is_default' => false,
    ]);

    $sinPerfil = resolve(RetentionYearsFloor::class)->forSite($site);

    DB::table('sites')->where('id', $site)->update(['compliance_profile_id' => $assigned]);
    // Enlace `scoped`: una ejecucion nueva lee la fila nueva.
    app()->forgetScopedInstances();
    $conPerfil = resolve(RetentionYearsFloor::class)->forSite($site);

    expect($sinPerfil->jurisdiction->code)->toBe('ES')
        ->and($sinPerfil->years)->toBe(4)
        ->and($sinPerfil->fromCatalog)->toBeTrue()
        ->and($conPerfil->jurisdiction->code)->toBe('PT')
        ->and($conPerfil->years)->toBe(1)
        ->and($conPerfil->fromCatalog)->toBeFalse()
        ->and(resolve(RetentionYearsFloor::class)->forJurisdiction(Jurisdiction::fromCode('ES-CN'))->years)->toBe(4);
})->group('RF-PD-07', 'RL-02');
