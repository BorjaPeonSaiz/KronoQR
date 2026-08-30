<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Compliance\RecordingIncidentResolutionMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Aislamiento por departamento en la bandeja de incidencias** (RF-ID-03,
 * RS-05, escenario Gherkin del doc 01 §11).
 *
 * Las dos mitades del requisito, que se aplican de forma distinta a proposito:
 *
 *   - El **listado** acota en la consulta y no devuelve `403`. Un responsable
 *     trabaja lo de su gente y no se entera de que existe mas; un `403` al
 *     listar convertiria su bandeja en un error permanente.
 *   - **Resolver** una incidencia ajena si es `403`, **y deja asiento**. Ahi hay
 *     un sujeto identificable al que apuntar en el trail, que es literalmente lo
 *     que el escenario exige: *«el intento queda registrado en el trail de
 *     auditoria»*.
 *
 * **Hay un solo centro** (ADR-040), asi que el unico eje de alcance es el
 * departamento y no hay ninguna prueba de frontera entre centros.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    config()->set('identity.two_factor.required_roles', []);

    app()->instance(Clock::class, FixedClock::at('2026-03-15 08:12:44'));
    app()->instance(IncidentResolutionMetrics::class, new RecordingIncidentResolutionMetrics);
});

/**
 * Un hotel con cocina y recepcion, una incidencia en cada una, y el responsable
 * de cocina con su token.
 *
 * @return array{cocina: int, recepcion: int, suya: int, ajena: int, deRecepcion: string, token: string}
 */
function escenarioDeAlcanceDeIncidencias(): array
{
    $site = WorkforceFixtures::site('Hotel de alcance', 'Europe/Madrid');
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $deCocina = WorkforceFixtures::employee($site, $cocina, 'active', 'Youssef', 'Amrani');
    $deRecepcion = WorkforceFixtures::employee($site, $recepcion, 'active', 'Lucia', 'Ferrer');

    return [
        'cocina' => $cocina,
        'recepcion' => $recepcion,
        'suya' => IncidentFixtures::open($deCocina, assignedToUserId: $jefeDeCocina->id),
        'ajena' => IncidentFixtures::open($deRecepcion, 'long_shift', 'medium', '2026-03-13'),
        'deRecepcion' => $deRecepcion,
        'token' => ManagementUsers::tokenFor($jefeDeCocina),
    ];
}

it('deja al responsable ver lo suyo y nada mas, ni en data ni en meta.total', function (): void {
    $escenario = escenarioDeAlcanceDeIncidencias();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/incidents');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.id'))->toBe($escenario['suya'])
        // La mitad que se olvida: el total tambien esta acotado. Contar sin
        // acotar y filtrar despues daria una cifra que describe a personas que
        // quien pregunta no puede ver.
        ->and($respuesta->json('meta.total'))->toBe(1)
        // Y ni el identificador ni el nombre de la otra persona aparecen en
        // ningun sitio de la respuesta.
        ->and((string) json_encode($respuesta->json()))->not->toContain($escenario['deRecepcion'])
        ->and((string) json_encode($respuesta->json()))->not->toContain('Ferrer');
})->group('RF-ID-03', 'RF-PA-05', 'RS-05');

it('devuelve vacio y no 403 cuando el responsable filtra por un departamento ajeno', function (): void {
    // Un filtro es una peticion de acotar, no la peticion de un recurso ajeno:
    // responder `403` convertiria el desplegable de departamentos del panel en un
    // generador de errores. Mismo criterio que `GET /employees`.
    $escenario = escenarioDeAlcanceDeIncidencias();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/incidents', ['department_id' => $escenario['recepcion']]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.total'))->toBe(0);
})->group('RF-ID-03', 'RF-PA-05');

it('no alcanza a nadie el responsable sin departamento asignado', function (): void {
    // El caso que convertiria a un responsable recien nombrado en alguien que ve
    // la bandeja del hotel entero si el alcance se representara con una lista
    // vacia indistinguible de «sin restriccion».
    $site = WorkforceFixtures::site('Hotel de alcance', 'Europe/Madrid');
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    IncidentFixtures::open(WorkforceFixtures::employee($site, $cocina));

    $reciente = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $respuesta = Api::as(ManagementUsers::tokenFor($reciente))->get('/api/v1/incidents');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.total'))->toBe(0);
})->group('RF-ID-03', 'RF-PA-05');

it('deniega resolver una incidencia de otro departamento y lo deja en auditoria', function (): void {
    // El escenario «Aislamiento por departamento» del doc 01 §11, aplicado a
    // RF-PA-05: `403` —no `404`, porque la incidencia existe y el problema es de
    // autorizacion— y el intento anotado.
    $escenario = escenarioDeAlcanceDeIncidencias();

    $respuesta = Api::as($escenario['token'])
        ->post('/api/v1/incidents/'.$escenario['ajena'].'/resolve', [
            'outcome' => 'resolved',
            'note' => 'No deberia poder cerrar esto.',
        ]);

    $respuesta->assertValidResponse(403);
    // Mismo cuerpo que cualquier otra denegacion: desde fuera no se distingue
    // «no tienes el rol» de «no alcanzas a esa incidencia».
    $respuesta->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    // El UUID de la persona afectada y el conjunto al que se intento llegar si;
    // el nombre de nadie, nunca (regla dura 21).
    expect($payload)->toContain($escenario['deRecepcion'])
        ->and($payload)->toContain('incident')
        ->and($payload)->not->toContain('Ferrer');

    // Y no se ha escrito nada: la incidencia sigue abierta y sin nota.
    $fila = IncidentFixtures::stored($escenario['ajena']);

    expect($fila->status)->toBe('open')
        ->and($fila->resolution_note)->toBeNull();
})->group('RF-ID-03', 'RS-05');

it('deja al responsable resolver lo de su propia gente', function (): void {
    // El control positivo. Sin el, la prueba de arriba pasaria igual si al
    // responsable se le hubiera negado la bandeja por completo.
    $escenario = escenarioDeAlcanceDeIncidencias();

    $respuesta = Api::as($escenario['token'])
        ->post('/api/v1/incidents/'.$escenario['suya'].'/resolve', [
            'outcome' => 'resolved',
            'note' => 'Hablado con la persona: se quedo a cerrar la cocina.',
        ]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('status'))->toBe('resolved')
        ->and(IncidentFixtures::stored($escenario['suya'])->status)->toBe('resolved');
})->group('RF-ID-03', 'RF-PA-05');
