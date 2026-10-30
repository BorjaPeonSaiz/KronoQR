<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\Probe\ScheduledOffboardingsProbe;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Las bajas programadas se buscan por su indice parcial** (RN-14, ADR-062 §4,
 * RNF-P-02).
 *
 * La tarea pregunta cada hora por las vencidas y `product:doctor` por las que
 * pasaron su tope. Con una plantilla de miles de fichas —la mayoria en alta sin
 * fecha de cese, o ya de baja— y estadisticas reales, las tres consultas tienen
 * que entrar por `scheduled_offboardings` y no recorrer `employees`. Que la sonda
 * lo use es la prueba de que su predicado es el mismo que el del repositorio y el
 * del indice.
 */

uses(CommittedDatabase::class);

function plantillaParaElIndiceDeBajas(int $site): void
{
    $filas = [];

    for ($i = 0; $i < 6_000; $i++) {
        $terminada = $i % 3 === 0;
        $programada = $i % 500 === 7;

        $filas[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $site,
            'first_name' => 'Persona',
            'last_name' => 'Indice '.$i,
            'employee_code' => 'IX'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'status' => $terminada ? 'terminated' : 'active',
            'hired_at' => '2026-01-01',
            'terminated_at' => $terminada ? '2026-06-30' : ($programada ? '2026-10-01' : null),
            'locale' => 'es',
            'created_at' => '2026-01-01 00:00:00+00',
            'updated_at' => '2026-01-01 00:00:00+00',
        ];
    }

    foreach (array_chunk($filas, 1_000) as $tanda) {
        WorkforceFixtures::insertEmployees($tanda);
    }

    QueryPlans::analyze('employees');
}

it('busca las bajas programadas vencidas y las pendientes por su indice parcial', function (string $consulta): void {
    FrozenTime::at('2026-10-05 09:00:00');
    plantillaParaElIndiceDeBajas(WorkforceFixtures::site('Hotel del indice'));
    $repositorio = app(EmployeeRepository::class);

    $resultado = null;
    $consultas = QueryPlans::selectsOn('employees', static function () use ($repositorio, $consulta, &$resultado): void {
        $resultado = match ($consulta) {
            'vencidas' => $repositorio->dueScheduledOffboardings('2026-10-05'),
            'pendientes' => $repositorio->countScheduledOffboardingsDueBefore('2026-10-04'),
            default => (new ScheduledOffboardingsProbe(DB::connection(), app(Clock::class)))->run(),
        };
    });

    expect($resultado)->not->toBeNull()
        ->and($consultas)->not->toBeEmpty();

    $ultima = end($consultas);
    expect($ultima)->toBeArray();
    /** @var array{sql: string, bindings: list<mixed>} $ultima */
    $nodos = QueryPlans::nodes($ultima);

    expect($nodos)->not->toBeEmpty('El plan de ejecucion no se pudo leer')
        ->and(QueryPlans::usesIndex($nodos, 'scheduled_offboardings'))->toBeTrue('La consulta no usa scheduled_offboardings')
        ->and(QueryPlans::scansSequentially($nodos, 'employees'))->toBeFalse('La consulta recorre employees');
})->with(['vencidas', 'pendientes', 'sonda de doctor'])->group('RN-14', 'RNF-P-02', 'RF-PD-13');

it('declara el indice con el mismo predicado que usan el repositorio y la sonda', function (): void {
    $indice = DB::table('pg_indexes')->where('indexname', 'scheduled_offboardings')->value('indexdef');
    $definicion = is_string($indice) ? $indice : '';

    expect($definicion)->toContain("status)::text <> 'terminated'")
        ->and($definicion)->toContain('terminated_at IS NOT NULL');
})->group('RN-14');
