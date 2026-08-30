<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PresenceFixtures;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/attendance/live` de punta a punta, validado contra
 * `docs/api/openapi.yaml` en cada respuesta (RF-PA-01, RF-PA-02).
 *
 * **El reloj esta detenido** (regla dura 2, ADR-021). `meta.generated_at` es la
 * referencia con la que el panel calcula el tiempo transcurrido, asi que tiene
 * que ser afirmable: con el reloj del sistema, la unica asercion posible seria
 * «trae algo que parece una fecha».
 *
 * **El alcance por departamento tiene su fichero aparte** —`LivePresenceScopeTest`—
 * porque ahi lo que se comprueba es RF-ID-03 y no la forma del endpoint.
 */

uses(RefreshDatabase::class);

const PRESENCIA_AHORA = '2026-03-14 09:12:03';

/**
 * Un hotel con dos departamentos: en cocina hay alguien dentro y en recepcion
 * alguien que ya se fue.
 *
 * @return array{site: int, cocina: int, recepcion: int, dentro: string, fuera: string, device: int, deviceUuid: string, token: string}
 */
function escenarioDePresencia(): array
{
    $site = WorkforceFixtures::site('Hotel de presencia', 'Europe/Madrid');
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $dentro = WorkforceFixtures::employee($site, $cocina, 'active', 'Youssef', 'Amrani');
    $fuera = WorkforceFixtures::employee($site, $recepcion, 'active', 'Lucia', 'Ferrer');

    $device = AttendanceFixtures::device($site, 'Entrada de personal');

    PresenceFixtures::openShift($dentro, $site, deviceId: $device['id']);
    PresenceFixtures::closedShift($fuera, $site);

    app()->instance(Clock::class, FixedClock::at(PRESENCIA_AHORA));

    Spectator::using('openapi.yaml');

    return [
        'site' => $site,
        'cocina' => $cocina,
        'recepcion' => $recepcion,
        'dentro' => $dentro,
        'fuera' => $fuera,
        'device' => $device['id'],
        'deviceUuid' => $device['uuid'],
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
    ];
}

it('devuelve quien esta dentro con su hora de entrada y su quiosco', function (): void {
    $escenario = escenarioDePresencia();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.employee_uuid'))->toBe($escenario['dentro'])
        ->and($respuesta->json('data.0.full_name'))->toBe('Youssef Amrani')
        ->and($respuesta->json('data.0.status'))->toBe('present')
        ->and($respuesta->json('data.0.department.id'))->toBe($escenario['cocina'])
        // La hora de entrada, en UTC y sin convertir (regla dura 3): la zona del
        // centro viaja aparte y la conversion la hace el panel.
        ->and($respuesta->json('data.0.clocked_in_at'))->toBe('2026-03-14T05:00:00.000000Z')
        ->and($respuesta->json('data.0.origin'))->toBe('qr_kiosk')
        // El quiosco de origen sale de `scan_events`, no de `shift_entries`.
        ->and($respuesta->json('data.0.device.uuid'))->toBe($escenario['deviceUuid'])
        // Y la referencia del tiempo transcurrido, que no es el reloj del cliente.
        ->and($respuesta->json('meta.generated_at'))->toBe('2026-03-14T09:12:03.000000Z')
        ->and($respuesta->json('meta.time_zone'))->toBe('Europe/Madrid');
})->group('RF-PA-01', 'RF-PA-02');

it('cuenta presentes y ausentes aunque solo devuelva una de las dos listas', function (): void {
    // Los recuentos describen el mismo conjunto que `data` salvo por el filtro
    // de situacion. Sin esto, el panel no podria enseñar «1 presente / 1
    // ausente» sin pedir la otra lista.
    $escenario = escenarioDePresencia();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.present_count'))->toBe(1)
        ->and($respuesta->json('meta.absent_count'))->toBe(1)
        ->and($respuesta->json('meta.total'))->toBe(2);
})->group('RF-PA-01', 'RF-PA-02');

it('filtra por situacion y quien ya salio aparece como ausente, no como presente', function (): void {
    $escenario = escenarioDePresencia();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live', ['status' => 'absent']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.employee_uuid'))->toBe($escenario['fuera'])
        ->and($respuesta->json('data.0.status'))->toBe('absent')
        // Los cinco campos de tramo faltan a la vez: no es informacion que falte,
        // es que no existe.
        ->and($respuesta->json('data.0.shift_entry_uuid'))->toBeNull()
        ->and($respuesta->json('data.0.clocked_in_at'))->toBeNull()
        ->and($respuesta->json('data.0.origin'))->toBeNull()
        ->and($respuesta->json('data.0.device'))->toBeNull();
})->group('RF-PA-02');

it('filtra por departamento', function (): void {
    $escenario = escenarioDePresencia();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['department_id' => $escenario['recepcion'], 'status' => 'absent']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.employee_uuid'))->toBe($escenario['fuera'])
        // Y el recuento acompaña al filtro: cocina no entra en ninguno de los dos.
        ->and($respuesta->json('meta.present_count'))->toBe(0)
        ->and($respuesta->json('meta.absent_count'))->toBe(1);
})->group('RF-PA-02');

it('busca por nombre sin distinguir mayusculas ni acentos', function (): void {
    // El mismo comportamiento que el cuadro de `GET /employees`: quien escribe en
    // una pantalla del panel espera lo mismo en la de al lado.
    $escenario = escenarioDePresencia();

    WorkforceFixtures::employee($escenario['site'], $escenario['cocina'], 'active', 'Monica', 'Garcia');

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['status' => 'absent', 'q' => 'garcía']);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.full_name'))->toBe('Monica Garcia');
})->group('RF-PA-02');

it('ordena por apellidos y nombre, con el mismo orden en cada sondeo', function (): void {
    // Un orden inestable haria que una fila saltara de sitio entre dos sondeos,
    // que en una lista que se refresca sola se lee como si hubiera cambiado algo.
    $escenario = escenarioDePresencia();

    WorkforceFixtures::employee($escenario['site'], $escenario['cocina'], 'active', 'Ana', 'Zabala');
    WorkforceFixtures::employee($escenario['site'], $escenario['cocina'], 'active', 'Bruno', 'Alonso');

    $primera = Api::as($escenario['token'])->get('/api/v1/attendance/live', ['status' => 'absent']);
    $segunda = Api::as($escenario['token'])->get('/api/v1/attendance/live', ['status' => 'absent']);

    $primera->assertValidResponse(200);

    expect(array_column((array) $primera->json('data'), 'full_name'))
        ->toBe(['Bruno Alonso', 'Lucia Ferrer', 'Ana Zabala'])
        ->and($segunda->json('data'))->toBe($primera->json('data'));
})->group('RF-PA-01');

it('no enseña a quien esta de baja, ni como presente ni como ausente', function (): void {
    // Es lo contrario del criterio de `GET /employees`, donde el historico se
    // conserva a la vista (RF-GP-03): aqui la pregunta es quien esta trabajando,
    // y alguien que ya no pertenece a la plantilla no es un ausente.
    $escenario = escenarioDePresencia();

    WorkforceFixtures::employee($escenario['site'], $escenario['cocina'], 'terminated', 'Cesado', 'Ejemplo');

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live', ['status' => 'absent']);

    $respuesta->assertValidResponse(200);

    expect(array_column((array) $respuesta->json('data'), 'full_name'))->not->toContain('Cesado Ejemplo')
        ->and($respuesta->json('meta.total'))->toBe(2);
})->group('RF-PA-01');

it('dice al panel a que canal suscribirse y cada cuanto sondear si no puede', function (): void {
    // ADR-011 y ADR-017: los datos de conexion viajan en la respuesta y no
    // compilados en la SPA, que se instala igual en todos los clientes.
    $escenario = escenarioDePresencia();

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.realtime.channels'))->toBe(['presence.all'])
        ->and($respuesta->json('meta.realtime.event'))->toBe('presence.updated')
        ->and($respuesta->json('meta.realtime.auth_endpoint'))->toBe('/api/v1/broadcasting/auth')
        ->and($respuesta->json('meta.realtime.poll_interval_seconds'))->toBe(15)
        // En la suite el difusor es `null`: la degradacion honesta se anuncia en
        // vez de dejar al panel reintentando contra un socket que no abre.
        ->and($respuesta->json('meta.realtime.enabled'))->toBeFalse();
})->group('RF-PA-01');

it('rechaza un filtro mal escrito en lugar de ignorarlo', function (): void {
    // Un `?stauts=present` devolveria otra cosa en silencio y quien lo escribio
    // se iria convencido de haber filtrado.
    $escenario = escenarioDePresencia();

    Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['stauts' => 'present'])
        ->assertStatus(422);

    Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['status' => 'dentro'])
        ->assertStatus(422);
})->group('RF-PA-02');

it('deja asiento de divulgacion con el alcance y nunca con lo divulgado', function (): void {
    // RS-05: la presencia es un conjunto de personas con nombre, departamento y
    // la hora a la que entraron a trabajar.
    $escenario = escenarioDePresencia();

    Api::as($escenario['token'])->get('/api/v1/attendance/live')->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderByDesc('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{action: string, subject_type: string, payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->action)->toBe('personal_data.accessed')
        ->and($asiento->subject_type)->toBe('personal_data')
        ->and($payload)->toMatchArray([
            'dataset' => 'live_presence',
            'record_count' => 1,
            'status' => 'present',
            'search' => false,
            'scope' => 'all',
        ])
        // Regla dura 21: el alcance, jamas lo divulgado.
        ->and($asiento->payload)->not->toContain('Youssef')
        ->and($asiento->payload)->not->toContain('Amrani');
})->group('RF-PA-01', 'RS-05', 'RL-15');

it('agrupa los asientos del sondeo sin perder el hecho de que el dato salio', function (): void {
    // El panel pide esto cada 15 s (RNF-D-03). Un asiento por sondeo llenaria la
    // tabla de cuatro años de retencion con la misma lectura y metería miles de
    // escrituras diarias bajo el candado global de ADR-010, el del fichaje.
    $escenario = escenarioDePresencia();

    for ($i = 0; $i < 4; $i++) {
        Api::as($escenario['token'])->get('/api/v1/attendance/live')->assertValidResponse(200);
    }

    $asientos = DB::table('audit_log')->where('action', 'personal_data.accessed')->count();

    expect($asientos)->toBe(1);

    // Y la repeticion no se pierde: viaja en el asiento siguiente. Se fuerza el
    // cierre de la ventana caducando el asiento, que es lo que hace el reloj en
    // una instalacion.
    cache()->flush();

    Api::as($escenario['token'])->get('/api/v1/attendance/live')->assertValidResponse(200);

    $ultimo = DB::table('audit_log')->orderByDesc('id')->first();

    expect($ultimo)->not->toBeNull();

    /** @var object{payload: string} $ultimo */
    expect(json_decode($ultimo->payload, true, 512, JSON_THROW_ON_ERROR))
        ->toHaveKey('dataset', 'live_presence');

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(2);
})->group('RF-PA-01', 'RS-05', 'RL-15');

it('acota el departamento pedido y lo hace en la consulta, tambien en los recuentos', function (): void {
    // Control de que `department_id` entra en el `WHERE` y no filtra la lista ya
    // traida: si filtrara despues, los recuentos describirian a gente que no
    // aparece en `data`.
    $escenario = escenarioDePresencia();

    $respuesta = Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['department_id' => $escenario['cocina']]);

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('meta.total'))->toBe(1);

    // Y un departamento que no existe es un `422`, no una lista vacia: hay una
    // errata que corregir.
    Department::query()->whereKey($escenario['cocina'])->delete();

    Api::as($escenario['token'])
        ->get('/api/v1/attendance/live', ['department_id' => 999999])
        ->assertStatus(422);
})->group('RF-PA-02');

it('anuncia el tiempo real cuando la instalacion lo tiene configurado', function (): void {
    // El control positivo de la degradacion: sin el, las aserciones de
    // `enabled: false` de arriba pasarian igual con el bloque `realtime` roto.
    $escenario = escenarioDePresencia();

    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.realtime.enabled'))->toBeTrue()
        ->and($respuesta->json('meta.realtime.key'))->toBe('kronoqr-test-key')
        ->and($respuesta->json('meta.realtime.path'))->toBe('/app');
})->group('RF-PA-01');

it('degrada a sondeo sin apagar la vista cuando el tiempo real esta desactivado', function (): void {
    // ADR-023: la presencia en vivo es la UNICA degradacion parcial de la lista
    // de funcionalidad accesoria. Apagarla del todo se percibiria como una
    // averia y no como una licencia caducada, asi que lo que se apaga es el
    // canal y la consulta sigue respondiendo (ADR-011, ADR-019, regla dura 15).
    //
    // El mismo camino que recorrera la caducidad de licencia cuando la tarea 5.3
    // cree el puerto: el punto de decision es unico y ya existe.
    $escenario = escenarioDePresencia();

    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
    config()->set('realtime.enabled', false);

    $respuesta = Api::as($escenario['token'])->get('/api/v1/attendance/live');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('meta.realtime.enabled'))->toBeFalse()
        // Y la vista SIGUE: los datos estan y el panel sabe cada cuanto pedirlos.
        ->and($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('meta.realtime.poll_interval_seconds'))->toBe(15);
})->group('RF-PA-01', 'RF-PD-05');
