<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Adapter\CatalogRetentionYearsFloor;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;

/*
 * El catalogo de suelos legales de la retencion (RL-02, RF-PD-07, ADR-057
 * enmienda del 10-10-2026). Es un dato legal del producto (regla dura 13): un
 * catalogo mal escrito es un fallo del producto y falla al construirse, con la
 * clave culpable en el mensaje.
 *
 * `forSite()` lee `compliance_profiles` y se prueba en integracion
 * (tests/Integration/Product/ComplianceProfileRetentionFloorSchemaTest.php).
 */

/**
 * El adaptador con el catalogo que se le diga y una conexion que no se usa.
 *
 * @param  array<mixed>  $jurisdictions
 */
function catalogRetentionFloor(int $default = 1, array $jurisdictions = ['ES' => 4]): CatalogRetentionYearsFloor
{
    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);

    return new CatalogRetentionYearsFloor($connection, $default, $jurisdictions);
}

it('da el suelo del catalogo a España y a sus subdivisiones', function (string $code): void {
    $floor = catalogRetentionFloor()->forJurisdiction(Jurisdiction::fromCode($code));

    expect($floor->years)->toBe(4)
        ->and($floor->fromCatalog)->toBeTrue()
        ->and($floor->jurisdiction->code)->toBe($code);
})->with(['ES', 'ES-CN'])->group('RF-PD-07', 'RL-02');

it('da el suelo de omision a una jurisdiccion fuera del catalogo', function (): void {
    $floor = catalogRetentionFloor(2, ['ES' => 4])->forJurisdiction(Jurisdiction::fromCode('PT'));

    expect($floor->years)->toBe(2)
        ->and($floor->fromCatalog)->toBeFalse()
        ->and($floor->jurisdiction->code)->toBe('PT');
})->group('RF-PD-07', 'RL-02');

it('se niega a construirse con un catalogo mal escrito, nombrando la clave', function (int $default, array $jurisdictions, string $message): void {
    expect(fn (): CatalogRetentionYearsFloor => catalogRetentionFloor($default, $jurisdictions))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'pais en minusculas' => [1, ['es' => 4], 'la clave «es»'],
    'alfa-3' => [1, ['ESP' => 4], 'la clave «ESP»'],
    'subdivision como clave' => [1, ['ES-CN' => 4], 'la clave «ES-CN»'],
    'clave numerica' => [1, [0 => 4], 'la clave «0»'],
    'suelo cero' => [1, ['ES' => 0], 'jurisdictions.ES tiene que ser un entero entre 1 y 50'],
    'suelo por debajo de la omision' => [3, ['ES' => 2], 'jurisdictions.ES tiene que ser un entero entre 3 y 50'],
    'suelo por encima del maximo del campo' => [1, ['ES' => 51], 'jurisdictions.ES'],
    'suelo como texto' => [1, ['ES' => '4'], 'jurisdictions.ES'],
    'omision cero' => [0, [], 'default tiene que estar entre 1 y 50; vale 0'],
    'omision por encima del maximo' => [51, [], 'default tiene que estar entre 1 y 50; vale 51'],
])->group('RF-PD-07');

it('admite los extremos del catalogo', function (): void {
    expect(catalogRetentionFloor(1, ['ES' => 1])->forJurisdiction(Jurisdiction::fromCode('ES'))->years)->toBe(1)
        ->and(catalogRetentionFloor(1, ['ES' => 50])->forJurisdiction(Jurisdiction::fromCode('ES'))->years)->toBe(50)
        ->and(catalogRetentionFloor(50, [])->forJurisdiction(Jurisdiction::fromCode('ES'))->years)->toBe(50);
})->group('RF-PD-07');
