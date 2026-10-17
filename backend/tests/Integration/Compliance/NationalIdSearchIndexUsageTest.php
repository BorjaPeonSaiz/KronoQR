<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\LegalExportEmployeeDirectory;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La busqueda por DNI de la persona de la exportacion legal usa el indice del
 * hash (RF-IN-05, RL-08).
 *
 * `national_id_hash = digest(?, ?)` tiene que resolverse con el indice unico
 * parcial `employees_national_id_hash_unique` y no recorriendo la plantilla: es
 * una igualdad exacta y no hay motivo para leer ni una fila mas que la que casa.
 * Dos cosas podrian romperlo sin que ninguna prueba funcional lo notara: que la
 * expresion dejara de ser `IMMUTABLE` para el planificador, o que la consulta
 * envolviera la columna en una funcion. Por eso se mira el plan, con una
 * plantilla de un tamaño en el que el planificador elige de verdad.
 */

uses(CommittedDatabase::class);

/** Personas con documento: suficientes para que un recorrido secuencial pierda. */
const NATIONAL_ID_SEARCH_INDEX_EMPLEADOS = 3_000;

it('busca por DNI con el indice del hash, sin recorrer employees', function (): void {
    $site = WorkforceFixtures::site('Hotel con plantilla grande');

    DB::insert(<<<'SQL'
        INSERT INTO employees (uuid, site_id, first_name, last_name, employee_code, national_id_hash,
                               status, hired_at, locale, created_at, updated_at)
        SELECT gen_random_uuid(), ?, 'Persona', 'Numero ' || g, 'IDX' || g,
               digest('DOC' || lpad(g::text, 6, '0') || 'X', 'sha256'),
               'active', DATE '2026-01-01', 'es', now(), now()
          FROM generate_series(1, ?) AS g
        SQL, [$site, NATIONAL_ID_SEARCH_INDEX_EMPLEADOS]);

    QueryPlans::analyze('employees');

    $directory = resolve(LegalExportEmployeeDirectory::class);
    $matches = null;

    $queries = QueryPlans::capture(
        static function () use ($directory, &$matches): void {
            // Escrito como lo teclearia una persona: el adaptador normaliza.
            $matches = $directory->search(EmployeeSearch::byNationalId('doc-001234-x'), 11);
        },
        static fn (string $sql): bool => str_contains($sql, 'from employees') && str_contains($sql, 'national_id_hash'),
    );

    expect($queries)->toHaveCount(1);

    $root = QueryPlans::root('EXPLAIN (FORMAT JSON) ', $queries[0]);

    expect($root)->not->toBeNull('El plan de ejecucion no se pudo leer');
    \assert($root !== null);

    $nodes = QueryPlans::flatten($root);

    expect(QueryPlans::usesIndex($nodes, 'employees_national_id_hash_unique'))
        ->toBeTrue('La busqueda por DNI no usa employees_national_id_hash_unique')
        ->and(QueryPlans::scansSequentially($nodes, 'employees'))
        ->toBeFalse('La busqueda por DNI recorre employees entera');

    // Y encuentra exactamente a quien tiene que encontrar.
    \assert(\is_array($matches));
    expect($matches)->toHaveCount(1)
        ->and($matches[0]->lastName)->toBe('Numero 1234');
})->group('RF-IN-05', 'RL-08', 'RNF-P-02');
