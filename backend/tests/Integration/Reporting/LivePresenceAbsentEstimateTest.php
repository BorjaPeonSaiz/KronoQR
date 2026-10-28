<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseLivePresenceReader;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabaseAsOwner;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La lista de ausentes de la presencia en vivo se estima bien (RF-PA-01,
 * RNF-P-02; V21-5-BE-2 de la 2.2.1).
 *
 * «Ausente» es quien no tiene tramo abierto: un `LEFT JOIN` con el tramo y el
 * filtro de que no hay. Con `se.id IS NULL` PostgreSQL no lo reconocia como un
 * anti-join —`id` no aparece en el `ON`— y lo estimaba como un filtro sobre
 * una columna que nunca es nula: **una fila** para 4.296 ausentes, y un bucle
 * anidado sobre `devices` por cada una. Con la columna del `ON`
 * (`se.employee_id`) lo convierte en anti-join y la estimacion cuadra.
 *
 * Lo que se afirma no depende del volumen: que el plan tiene un anti-join, y
 * que la fila estimada y la real no se separan en un orden de magnitud. El SQL
 * se captura del adaptador ({@see QueryPlans}).
 */

uses(RefreshDatabaseAsOwner::class);

/** Personas de alta, de las que un tercio esta dentro. */
const LIVE_PRESENCE_ABSENT_EMPLEADOS = 300;

it('estima la lista de ausentes como un anti-join y no como una sola fila', function (): void {
    $site = WorkforceFixtures::site('Hotel de la presencia');

    for ($i = 0; $i < LIVE_PRESENCE_ABSENT_EMPLEADOS; $i++) {
        WorkforceFixtures::employee($site);
    }

    DB::statement(<<<'SQL'
        INSERT INTO shift_entries
            (uuid, employee_id, site_id, work_date, clocked_in_at, status, clock_in_source, version, created_at, updated_at)
        SELECT gen_random_uuid(), e.id, e.site_id, CURRENT_DATE, now() - interval '2 hours', 'open', 'qr_kiosk', 1, now(), now()
          FROM employees e
         WHERE e.id % 3 = 0
        SQL);

    QueryPlans::analyze('employees', 'shift_entries');

    $reader = new DatabaseLivePresenceReader(DB::connection());

    $queries = QueryPlans::capture(
        static fn (): mixed => $reader->board(
            AccessScope::unrestricted(),
            null,
            null,
            PresenceStatus::Absent,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'Europe/Madrid',
        ),
        static fn (string $sql): bool => str_contains($sql, 'order by lower(e.last_name)'),
    );

    expect($queries)->toHaveCount(1);

    $root = QueryPlans::root('EXPLAIN (ANALYZE, FORMAT JSON) ', $queries[0]);

    expect($root)->not->toBeNull();
    assert($root !== null);

    $antiJoin = array_any(
        QueryPlans::flatten($root),
        static fn (array $node): bool => in_array($node['Join Type'] ?? '', ['Anti', 'Right Anti'], true),
    );

    $estimated = $root['Plan Rows'] ?? 0;
    $actual = $root['Actual Rows'] ?? 0;

    expect($antiJoin)->toBeTrue('La lista de ausentes no se planifica como anti-join')
        ->and($actual)->toBe(LIVE_PRESENCE_ABSENT_EMPLEADOS - intdiv(LIVE_PRESENCE_ABSENT_EMPLEADOS, 3))
        ->and($estimated)->toBeGreaterThan(intdiv(is_int($actual) ? $actual : 0, 10));
})->group('RF-PA-01', 'RNF-P-02');
