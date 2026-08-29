<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * Un centro de trabajo por instalacion (ADR-040), impuesto por el esquema.
 *
 * La garantia no es un `if` en el caso de uso sino el indice unico sobre una
 * expresion constante: el segundo `INSERT` en `sites` falla tambien para quien
 * escriba con `DB::table()` o desde `psql`.
 */

uses(RefreshDatabase::class);

function insertSite(string $name): int
{
    return DB::table('sites')->insertGetId([
        'name' => $name,
        'timezone' => 'Europe/Madrid',
        'settings' => '{}',
        'created_at' => '2026-01-01 00:00:00+00',
    ]);
}

it('admite el primer centro', function (): void {
    expect(insertSite('Hotel Marina'))->toBeGreaterThan(0);
})->group('RF-GP-01');

it('rechaza un segundo centro aunque tenga otro nombre y otra zona', function (): void {
    insertSite('Hotel Marina');

    expect(fn () => insertSite('Hotel Atlantico'))
        ->toThrow(QueryException::class, 'sites_single_row_uidx');
})->group('RF-GP-01', 'RN-05');

it('declara el indice como unico en el catalogo de PostgreSQL', function (): void {
    // Que exista con ese nombre es lo que permite al repositorio traducir la
    // violacion a `SiteAlreadyConfigured` en vez de a un 500.
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'sites')
        ->where('indexname', 'sites_single_row_uidx')
        ->value('indexdef');

    expect($definition)->toBeString()
        ->and($definition)->toContain('UNIQUE INDEX');
})->group('RF-GP-01');
