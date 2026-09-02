<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\PresenceFixtures;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Aislamiento por departamento en la vista de presencia** (RF-ID-03, RS-04).
 *
 * El listado acota **en la consulta** y no devuelve `403` (docblock de
 * `ScopeGuard`): un responsable ve a su gente y no se entera de que existe mas.
 * Lo que aqui se comprueba es que la acotacion alcanza tambien a los
 * **recuentos** de `meta`, que es la mitad que se olvida: un `present_count` que
 * incluyera a gente de otro departamento seria una fuga aunque `data` estuviera
 * bien filtrado, porque diria cuanta gente hay dentro en un sitio que quien
 * pregunta no puede ver.
 *
 * **Hay un solo centro** (ADR-040), asi que el unico eje de alcance es el
 * departamento y no hay ninguna prueba de frontera entre centros.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El responsable no esta entre los roles que RS-06 obliga a segundo factor;
    // se deja explicito para no depender del valor de serie.
    config()->set('identity.two_factor.required_roles', []);

    app()->instance(Clock::class, FixedClock::at('2026-03-14 09:12:03'));

    /*
     * Reverb en pie y una licencia que conceda el tiempo real (tarea 5.3).
     *
     * Desde la 5.3, `meta.realtime.channels` va VACIO cuando no hay tiempo
     * real —sea cual sea el motivo—, por lo mismo que `key` va nula: sin
     * canales no hay nada que pedir, y es incoherente entregarle a un cliente
     * lo que necesita para conectarse justo despues de decirle que no.
     *
     * Estas pruebas miran el ALCANCE (RF-ID-03), no la degradacion, asi que
     * necesitan la respuesta en su forma de produccion. La degradacion tiene
     * fichero propio: `tests/Feature/Product/LicenseDegradesAccessoriesTest.php`.
     */
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
    LicenseKeys::grantAll();
});

/**
 * Un hotel con cocina y recepcion, una persona dentro en cada una, y el
 * responsable de cocina con su token.
 *
 * @return array{site: int, cocina: int, recepcion: int, deCocina: string, deRecepcion: string, token: string}
 */
function escenarioDeAlcanceDePresencia(): array
{
    $site = WorkforceFixtures::site('Hotel de alcance', 'Europe/Madrid');
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $deCocina = WorkforceFixtures::employee($site, $cocina, 'active', 'Youssef', 'Amrani');
    $deRecepcion = WorkforceFixtures::employee($site, $recepcion, 'active', 'Lucia', 'Ferrer');

    PresenceFixtures::openShift($deCocina, $site);
    PresenceFixtures::openShift($deRecepcion, $site);

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
    $escenario = escenarioDeAlcanceDePresencia();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.employee_uuid'))->toBe($escenario['deCocina'])
        // La mitad que se olvida: el recuento tambien esta acotado.
        ->and($respuesta->json('meta.present_count'))->toBe(1)
        ->and($respuesta->json('meta.absent_count'))->toBe(0)
        ->and($respuesta->json('meta.total'))->toBe(1)
        // Y ni el nombre ni el identificador de la otra persona aparecen en
        // ningun sitio de la respuesta.
        ->and((string) json_encode($respuesta->json()))->not->toContain($escenario['deRecepcion'])
        ->and((string) json_encode($respuesta->json()))->not->toContain('Ferrer');
})->group('RF-ID-03', 'RF-PA-01', 'RS-04');

it('devuelve vacio y no 403 cuando el responsable filtra por un departamento ajeno', function (): void {
    // Un filtro es una peticion de acotar, no la peticion de un recurso ajeno:
    // responder `403` convertiria el desplegable de departamentos del panel en
    // un generador de errores. Mismo criterio que `GET /employees`.
    $escenario = escenarioDeAlcanceDePresencia();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['department_id' => $escenario['recepcion']]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.total'))->toBe(0);
})->group('RF-ID-03', 'RS-04');

it('no le concede el canal global aunque le conceda los suyos', function (): void {
    // Suscribir a un responsable al canal de la instalacion le daria en tiempo
    // real justo lo que RF-ID-03 le niega en el listado — y se lo seguiria dando
    // cuando se cree un departamento nuevo que no dirige.
    $escenario = escenarioDeAlcanceDePresencia();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.realtime.channels'))
        ->toBe(['presence.department.'.$escenario['cocina']]);
})->group('RF-ID-03', 'RS-04');

it('no enseña a nadie a un responsable sin departamento asignado', function (): void {
    // Es el fallo que RF-ID-03 existe para impedir: una lista de departamentos
    // vacia significa «nadie», no «sin restriccion». Y aun asi el endpoint
    // responde: la lista vacia es la respuesta correcta, no un `403`.
    $site = WorkforceFixtures::site('Hotel de alcance', 'Europe/Madrid');
    $departamento = WorkforceFixtures::department($site, 'Cocina');
    $alguien = WorkforceFixtures::employee($site, $departamento, 'active', 'Youssef', 'Amrani');

    PresenceFixtures::openShift($alguien, $site);

    $huerfano = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $respuesta = Api::as(ManagementUsers::tokenFor($huerfano))->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.present_count'))->toBe(0)
        ->and($respuesta->json('meta.absent_count'))->toBe(0)
        ->and($respuesta->json('meta.realtime.channels'))->toBe([]);
})->group('RF-ID-03', 'RS-04');

it('deja a RRHH ver el hotel entero, que es lo que hace significativo el resto', function (): void {
    // El control positivo. Sin el, las pruebas de arriba pasarian igual con el
    // endpoint devolviendo siempre una lista vacia.
    $escenario = escenarioDeAlcanceDePresencia();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $respuesta = Api::as($token)->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(2)
        ->and($respuesta->json('meta.present_count'))->toBe(2)
        ->and($respuesta->json('meta.realtime.channels'))->toBe(['presence.all']);
})->group('RF-ID-03', 'RF-PA-01');
