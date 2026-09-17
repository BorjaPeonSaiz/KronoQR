<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Aislamiento por departamento en la vista de cumplimiento** (RF-ID-03, RS-04,
 * tarea 3.4).
 *
 * La vista acota **en la consulta** y no devuelve `403` (docblock de
 * `ScopeGuard`): un responsable ve a su gente y no se entera de que existe mas.
 *
 * Lo que aqui se comprueba es que la acotacion alcanza tambien a **`meta.totals`**,
 * que es la mitad que se olvida: un `employees_affected` que incluyera a gente de
 * otro departamento seria una fuga aunque `data` estuviera bien filtrado, porque
 * diria cuanta gente incumple en un sitio que quien pregunta no puede ver. Y en
 * esta pantalla el dato es peor que en la presencia: alli se filtra quien esta
 * dentro; aqui, quien ha incumplido.
 *
 * **Hay un solo centro** (ADR-040), asi que el unico eje de alcance es el
 * departamento.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    config()->set('identity.two_factor.required_roles', []);

    FrozenTime::at('2026-03-31 09:12:03');
});

/**
 * Un hotel con cocina y recepcion, una persona incumpliendo en cada una, y el
 * responsable de cocina con su token.
 *
 * Las dos hacen lo mismo: 8 h el lunes y 9 h 30 el martes entrando nueve horas
 * despues de salir. Asi los dos hallazgos —RN-10 y RN-11— existen a los dos
 * lados de la frontera y lo unico que los distingue es el departamento.
 *
 * @return array{site: int, cocina: int, recepcion: int, deCocina: string, deRecepcion: string, token: string}
 */
function escenarioDeAlcanceDeCumplimiento(): array
{
    $site = WorkforceFixtures::site('Hotel de alcance', 'Europe/Madrid');
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $deCocina = WorkforceFixtures::employee($site, $cocina, 'active', 'Youssef', 'Amrani');
    $deRecepcion = WorkforceFixtures::employee($site, $recepcion, 'active', 'Lucia', 'Ferrer');

    foreach ([$deCocina, $deRecepcion] as $employee) {
        PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
        PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');
    }

    return [
        'site' => $site,
        'cocina' => $cocina,
        'recepcion' => $recepcion,
        'deCocina' => $deCocina,
        'deRecepcion' => $deRecepcion,
        'token' => ManagementUsers::tokenFor($jefeDeCocina),
    ];
}

it('deja al responsable ver a su gente y a nadie mas, ni en data ni en los recuentos', function (): void {
    $escenario = escenarioDeAlcanceDeCumplimiento();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(2)
        ->and($respuesta->json('data.0.employee.uuid'))->toBe($escenario['deCocina'])
        ->and($respuesta->json('data.1.employee.uuid'))->toBe($escenario['deCocina'])
        // La mitad que se olvida: los recuentos tambien estan acotados.
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(1)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(1)
        ->and($respuesta->json('meta.totals.by_rule.insufficient_rest'))->toBe(1)
        ->and($respuesta->json('meta.totals.by_rule.daily_excess'))->toBe(1)
        // Y el alcance con el que se sirvio va en la respuesta.
        ->and($respuesta->json('meta.scope'))->toBe('departments')
        // Ni el nombre ni el identificador de la otra persona aparecen en ningun
        // sitio.
        ->and((string) json_encode($respuesta->json()))->not->toContain($escenario['deRecepcion'])
        ->and((string) json_encode($respuesta->json()))->not->toContain('Ferrer');
})->group('RF-ID-03', 'RS-04', 'RF-PA-06');

it('devuelve vacio y no 403 cuando el responsable filtra por un departamento ajeno', function (): void {
    // Un filtro es una peticion de acotar, no la peticion de un recurso ajeno:
    // responder `403` convertiria el desplegable de departamentos del panel en un
    // generador de errores, y ademas confirmaria que ese departamento existe.
    $escenario = escenarioDeAlcanceDeCumplimiento();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/compliance/summary', [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'department_id' => $escenario['recepcion'],
    ]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(0)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(0)
        // Y el criterio sigue viajando entero: la pantalla vacia tambien explica
        // con que se ha medido.
        ->and($respuesta->json('meta.rules'))->toHaveCount(4)
        ->and($respuesta->json('meta.criteria'))->toHaveCount(5);
})->group('RF-ID-03', 'RS-04', 'RF-PA-06');

it('devuelve vacio y no 403 cuando el responsable filtra por una persona ajena', function (): void {
    // El `employee_uuid` no se valida contra `employees` a proposito: hacerlo
    // convertiria este endpoint en un oraculo de existencia —un `422` diria «ese
    // identificador no existe» y un `200` vacio diria «existe y no es tuyo»—.
    $escenario = escenarioDeAlcanceDeCumplimiento();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/compliance/summary', [
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'employee_uuid' => $escenario['deRecepcion'],
    ]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(0);
})->group('RF-ID-03', 'RS-04', 'RF-PA-06');

it('no enseña a nadie al responsable que todavia no dirige ningun departamento', function (): void {
    /*
     * La rama que nadie ejecutaba, y la que mas caro se paga: un
     * `responsable_departamento` recien creado al que aun no se le ha asignado
     * ningun departamento. Su alcance no alcanza a nadie, y el adaptador lo
     * traduce a un predicado imposible (`AND 1 = 0`).
     *
     * **«Sin departamentos» no puede significar «sin filtro».** Si el predicado se
     * omitiera, esa cuenta veria el cumplimiento del hotel entero —con nombres y
     * en que ha incumplido cada uno— por el simple hecho de no tener nada
     * asignado todavia. Es el fallo clasico del alcance por lista vacia, y la
     * unica forma de descartarlo es ejecutarlo (RF-ID-03, RS-04).
     */
    $escenario = escenarioDeAlcanceDeCumplimiento();

    $reciente = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $respuesta = Api::as(ManagementUsers::tokenFor($reciente))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    // Acota, no deniega: la pantalla se abre vacia, no con un error.
    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(0)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(0)
        ->and($respuesta->json('meta.totals.by_rule.insufficient_rest'))->toBe(0)
        ->and($respuesta->json('meta.totals.by_rule.daily_excess'))->toBe(0)
        ->and($respuesta->json('meta.scope'))->toBe('departments')
        // Y ni un rastro de las dos personas que si incumplen.
        ->and((string) json_encode($respuesta->json()))->not->toContain($escenario['deCocina'])
        ->and((string) json_encode($respuesta->json()))->not->toContain($escenario['deRecepcion'])
        ->and((string) json_encode($respuesta->json()))->not->toContain('Amrani')
        ->and((string) json_encode($respuesta->json()))->not->toContain('Ferrer');
})->group('RF-ID-03', 'RS-04', 'RQ-07', 'RF-PA-06');

it('sirve a RRHH el hotel entero, que es lo que hace util la comparacion', function (): void {
    // El contraste que demuestra que lo de arriba es acotacion y no un fallo de
    // los datos: con alcance sin restringir salen las dos personas.
    escenarioDeAlcanceDeCumplimiento();

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/compliance/summary', ['from' => '2026-03-01', 'to' => '2026-03-31']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(4)
        ->and($respuesta->json('meta.totals.employees_affected'))->toBe(2)
        ->and($respuesta->json('meta.totals.employees_evaluated'))->toBe(2)
        ->and($respuesta->json('meta.scope'))->toBe('all');
})->group('RF-ID-03', 'RF-PA-06');
