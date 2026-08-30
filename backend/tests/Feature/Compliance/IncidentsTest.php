<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
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
 * La bandeja de incidencias y su flujo de resolucion (**RF-PA-05**, tarea 2.5).
 *
 * Feature y contrato a la vez: cada respuesta se valida contra
 * `docs/api/openapi.yaml` con `assertValidResponse()`, que es lo que impide que
 * el codigo y el contrato se separen sin que nadie se entere — y el contrato es
 * de donde las tres SPA generan sus tipos.
 *
 * EL RELOJ ESTA FIJO (regla dura 2). Sin eso, `meta.generated_at`, `resolved_at`
 * y sobre todo los segundos que observa `incident_resolution_seconds` serian
 * cifras que cambian segun la hora a la que corra la suite.
 */

uses(RefreshDatabase::class);

/** El «ahora» de todas las pruebas de este fichero. */
const INCIDENT_NOW = '2026-03-15 08:12:44';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El responsable no esta entre los roles que RS-06 obliga a segundo factor;
    // se deja explicito para no depender del valor de serie.
    config()->set('identity.two_factor.required_roles', []);

    app()->instance(Clock::class, FixedClock::at(INCIDENT_NOW));
});

/**
 * Un hotel con una persona en cocina y una cuenta de RRHH que trabaja la bandeja.
 *
 * @return array{site: int, department: int, employee: string, userId: int, token: string}
 */
function escenarioDeBandeja(): array
{
    $site = WorkforceFixtures::site('Hotel de incidencias', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani', 'E7QK2MXPR');
    $user = ManagementUsers::withRole(UserRole::RRHH);

    return [
        'site' => $site,
        'department' => $department,
        'employee' => $employee,
        'userId' => $user->id,
        'token' => ManagementUsers::tokenFor($user),
    ];
}

/** El doble de la metrica, enlazado y devuelto para poder leerlo. */
function grabadorDeResoluciones(): RecordingIncidentResolutionMetrics
{
    $metrics = new RecordingIncidentResolutionMetrics;

    app()->instance(IncidentResolutionMetrics::class, $metrics);

    return $metrics;
}

it('devuelve la bandeja con todo lo que hace falta para trabajarla', function (): void {
    $escenario = escenarioDeBandeja();
    IncidentFixtures::open($escenario['employee'], assignedToUserId: $escenario['userId']);

    $respuesta = Api::as($escenario['token'])->get('/api/v1/incidents');

    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.type'))->toBe('insufficient_rest')
        ->and($respuesta->json('data.0.severity'))->toBe('high')
        ->and($respuesta->json('data.0.status'))->toBe('open')
        ->and($respuesta->json('data.0.employee.uuid'))->toBe($escenario['employee'])
        ->and($respuesta->json('data.0.employee.employee_code'))->toBe('E7QK2MXPR')
        ->and($respuesta->json('data.0.employee.full_name'))->toBe('Youssef Amrani')
        ->and($respuesta->json('data.0.employee.department.name'))->toContain('Cocina')
        ->and($respuesta->json('data.0.work_date'))->toBe('2026-03-14')
        ->and($respuesta->json('data.0.detected_at'))->toBe('2026-03-15T03:30:00.000000Z')
        // Los numeros con los que se abrio: el umbral puede cambiar despues
        // (RF-PD-07) y sin ellos la incidencia no se explica meses mas tarde.
        ->and($respuesta->json('data.0.context'))->toBe(['rest_minutes' => 420, 'threshold_minutes' => 720])
        ->and($respuesta->json('data.0.assigned_to.name'))->toContain('rrhh')
        // Los tres campos del cierre son nulos a la vez mientras siga abierta.
        ->and($respuesta->json('data.0.resolved_at'))->toBeNull()
        ->and($respuesta->json('data.0.resolved_by'))->toBeNull()
        ->and($respuesta->json('data.0.resolution_note'))->toBeNull()
        // La referencia contra la que el panel calcula la antiguedad, y la zona
        // en la que la muestra (regla dura 3).
        ->and($respuesta->json('meta.generated_at'))->toBe('2026-03-15T08:12:44.000000Z')
        ->and($respuesta->json('meta.time_zone'))->toBe('Europe/Madrid')
        ->and($respuesta->json('meta.total'))->toBe(1)
        ->and($respuesta->json('meta.total_pages'))->toBe(1);
})->group('RF-PA-05');

it('enseña solo lo abierto cuando no se pide otra cosa', function (): void {
    // El valor por omision es la pregunta de quien abre la bandeja: que tengo
    // pendiente. Lo ya trabajado no desaparece —nada se borra— pero hay que
    // pedirlo.
    $escenario = escenarioDeBandeja();
    $abierta = IncidentFixtures::open($escenario['employee']);
    $cerrada = IncidentFixtures::closed($escenario['employee'], $escenario['userId']);

    $porOmision = Api::as($escenario['token'])->get('/api/v1/incidents');
    $porOmision->assertValidResponse(200);

    expect($porOmision->json('data'))->toHaveCount(1)
        ->and($porOmision->json('data.0.id'))->toBe($abierta);

    $resueltas = Api::as($escenario['token'])->get('/api/v1/incidents', ['status' => 'resolved']);
    $resueltas->assertValidResponse(200);

    expect($resueltas->json('data'))->toHaveCount(1)
        ->and($resueltas->json('data.0.id'))->toBe($cerrada)
        ->and($resueltas->json('data.0.resolution_note'))->toBe('Revisado con el parte de turno.');
})->group('RF-PA-05');

it('filtra por tipo, severidad, departamento y persona', function (): void {
    // El filtro por tipo hace falta desde el primer dia: una instalacion con
    // turnos continuados acumula cientos de `missing_break` que taparian a los
    // `insufficient_rest`, que son los que tienen consecuencia sancionadora.
    $escenario = escenarioDeBandeja();
    $otroDepartamento = WorkforceFixtures::department($escenario['site'], 'Recepcion');
    $otraPersona = WorkforceFixtures::employee($escenario['site'], $otroDepartamento, 'active', 'Lucia', 'Ferrer');

    $descanso = IncidentFixtures::open($escenario['employee']);
    IncidentFixtures::open($escenario['employee'], 'missing_break', 'medium', '2026-03-13');
    IncidentFixtures::open($otraPersona, 'clock_skew', 'low', '2026-03-13');

    $porTipo = Api::as($escenario['token'])->get('/api/v1/incidents', ['type' => 'insufficient_rest']);
    $porTipo->assertValidResponse(200);
    expect($porTipo->json('data'))->toHaveCount(1)->and($porTipo->json('data.0.id'))->toBe($descanso);

    $porSeveridad = Api::as($escenario['token'])->get('/api/v1/incidents', ['severity' => 'low']);
    $porSeveridad->assertValidResponse(200);
    expect($porSeveridad->json('data'))->toHaveCount(1)
        ->and($porSeveridad->json('data.0.type'))->toBe('clock_skew');

    $porDepartamento = Api::as($escenario['token'])
        ->get('/api/v1/incidents', ['department_id' => $escenario['department']]);
    $porDepartamento->assertValidResponse(200);
    expect($porDepartamento->json('meta.total'))->toBe(2);

    $porPersona = Api::as($escenario['token'])
        ->get('/api/v1/incidents', ['employee_uuid' => $otraPersona]);
    $porPersona->assertValidResponse(200);
    expect($porPersona->json('data'))->toHaveCount(1)
        ->and($porPersona->json('data.0.employee.uuid'))->toBe($otraPersona);
})->group('RF-PA-05');

it('ordena por severidad y despues por lo mas reciente', function (): void {
    // Es el orden de trabajo, no el de creacion. Y no puede salir del orden
    // alfabetico de la cadena: alfabeticamente `high` < `low` < `medium`, y la
    // bandeja saldria con lo menos urgente en medio.
    $escenario = escenarioDeBandeja();
    IncidentFixtures::open($escenario['employee'], 'short_shift', 'low', '2026-03-14', '2026-03-15 06:00:00+00');
    IncidentFixtures::open($escenario['employee'], 'long_shift', 'medium', '2026-03-13', '2026-03-15 05:00:00+00');
    IncidentFixtures::open($escenario['employee'], 'insufficient_rest', 'high', '2026-03-12', '2026-03-15 04:00:00+00');
    IncidentFixtures::open($escenario['employee'], 'missing_break', 'medium', '2026-03-11', '2026-03-15 07:00:00+00');

    $respuesta = Api::as($escenario['token'])->get('/api/v1/incidents');
    $respuesta->assertValidResponse(200);

    expect($respuesta->json('data.*.type'))
        ->toBe(['insufficient_rest', 'missing_break', 'long_shift', 'short_shift']);
})->group('RF-PA-05');

it('pagina y no sirve la bandeja entera', function (): void {
    // La vista virtualiza, pero la API no devuelve tablas enteras: un hotel con
    // turnos continuados puede acumular cientos de incidencias del mismo tipo.
    $escenario = escenarioDeBandeja();

    for ($i = 0; $i < 5; $i++) {
        IncidentFixtures::open(
            $escenario['employee'],
            'clock_skew',
            'low',
            '2026-03-0'.($i + 1),
            '2026-03-15 0'.$i.':00:00+00',
        );
    }

    $primera = Api::as($escenario['token'])->get('/api/v1/incidents', ['per_page' => 2]);
    $primera->assertValidResponse(200);

    expect($primera->json('data'))->toHaveCount(2)
        ->and($primera->json('meta.total'))->toBe(5)
        ->and($primera->json('meta.total_pages'))->toBe(3)
        ->and($primera->json('meta.per_page'))->toBe(2);

    $ultima = Api::as($escenario['token'])->get('/api/v1/incidents', ['per_page' => 2, 'page' => 3]);
    $ultima->assertValidResponse(200);

    expect($ultima->json('data'))->toHaveCount(1)
        ->and($ultima->json('meta.page'))->toBe(3);

    // Y el techo de `per_page` es del contrato, no una sugerencia.
    Api::as($escenario['token'])->get('/api/v1/incidents', ['per_page' => 500])->assertStatus(422);
})->group('RF-PA-05');

it('rechaza un filtro que no existe en lugar de ignorarlo', function (): void {
    // Un `?severidad=high` devolveria la bandeja entera en silencio y quien lo
    // escribio se iria convencido de haber filtrado justo lo urgente.
    $escenario = escenarioDeBandeja();

    Api::as($escenario['token'])->get('/api/v1/incidents', ['severidad' => 'high'])->assertStatus(422);
    Api::as($escenario['token'])->get('/api/v1/incidents', ['status' => 'pendiente'])->assertStatus(422);
})->group('RF-PA-05');

it('resuelve una incidencia, la retira de la bandeja y deja su nota', function (): void {
    $escenario = escenarioDeBandeja();
    $metrics = grabadorDeResoluciones();
    $incidente = IncidentFixtures::open($escenario['employee'], assignedToUserId: $escenario['userId']);

    $respuesta = Api::as($escenario['token'])->post('/api/v1/incidents/'.$incidente.'/resolve', [
        'outcome' => 'resolved',
        'note' => 'Corregida la salida olvidada del dia 14 con el parte de turno.',
    ]);

    $respuesta->assertValidRequest();
    $respuesta->assertValidResponse(200);

    expect($respuesta->json('id'))->toBe($incidente)
        ->and($respuesta->json('status'))->toBe('resolved')
        ->and($respuesta->json('resolved_at'))->toBe('2026-03-15T08:12:44.000000Z')
        ->and($respuesta->json('resolved_by.name'))->toContain('rrhh')
        ->and($respuesta->json('resolution_note'))
        ->toBe('Corregida la salida olvidada del dia 14 con el parte de turno.');

    // Y ya no esta en lo pendiente, que es el efecto que se busca.
    $bandeja = Api::as($escenario['token'])->get('/api/v1/incidents');
    $bandeja->assertValidResponse(200);

    expect($bandeja->json('data'))->toBe([])
        ->and($bandeja->json('meta.total'))->toBe(0);

    // La fila, tal y como quedo escrita.
    $fila = IncidentFixtures::stored($incidente);

    expect($fila->status)->toBe('resolved')
        ->and($fila->resolved_by_user_id)->toBe($escenario['userId'])
        ->and($fila->resolution_note)->toBe('Corregida la salida olvidada del dia 14 con el parte de turno.');

    // `incident_resolution_seconds{type}`: entre la deteccion (03:30) y la
    // resolucion (08:12:44) hay 4 h 42 min 44 s.
    expect($metrics->observations())->toBe([
        ['type' => 'insufficient_rest', 'seconds' => 16964],
    ]);
})->group('RF-PA-05', 'RN-13');

it('deja asiento incident.resolved con el actor real y sin nombres', function (): void {
    // Regla dura 6: toda accion con relevancia legal escribe en `audit_log`. Y
    // regla dura 21: el payload lleva `employee_uuid`, nunca el nombre — el
    // trail viaja entero en la exportacion legal.
    $escenario = escenarioDeBandeja();
    grabadorDeResoluciones();
    $incidente = IncidentFixtures::open($escenario['employee']);

    Api::as($escenario['token'])->post('/api/v1/incidents/'.$incidente.'/resolve', [
        'outcome' => 'dismissed',
        'note' => 'Doblo turno con autorizacion escrita.',
    ])->assertStatus(200);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::IncidentResolved->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    /** @var object{actor_type: string, actor_id: int|null, subject_type: string, subject_id: int|null, payload: string} $asiento */
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        // El actor real de la peticion, no `system` como en la apertura.
        ->and($asiento->actor_id)->toBe($escenario['userId'])
        ->and($asiento->subject_type)->toBe('incident')
        ->and($asiento->subject_id)->toBe($incidente)
        ->and($payload)->toMatchArray([
            'employee_uuid' => $escenario['employee'],
            'type' => 'insufficient_rest',
            'severity' => 'high',
            'outcome' => 'dismissed',
            'note' => 'Doblo turno con autorizacion escrita.',
            'work_date' => '2026-03-14',
        ])
        ->and($payload['resolution_seconds'])->toBe(16964)
        // Ni el nombre ni el apellido de nadie en el asiento.
        ->and((string) $asiento->payload)->not->toContain('Youssef')
        ->and((string) $asiento->payload)->not->toContain('Amrani');
})->group('RF-PA-05', 'RL-01', 'RS-05');

it('devuelve 409 al resolver una que ya estaba cerrada, y no escribe una segunda nota', function (): void {
    // La accion siguiente es releer la bandeja, no reescribir la nota: por eso
    // `409` y no `422`.
    $escenario = escenarioDeBandeja();
    $metrics = grabadorDeResoluciones();
    $incidente = IncidentFixtures::open($escenario['employee']);

    Api::as($escenario['token'])->post('/api/v1/incidents/'.$incidente.'/resolve', [
        'outcome' => 'resolved',
        'note' => 'La primera nota, que es la que vale.',
    ])->assertStatus(200);

    $segunda = Api::as($escenario['token'])->post('/api/v1/incidents/'.$incidente.'/resolve', [
        'outcome' => 'dismissed',
        'note' => 'La segunda, que no deberia poder escribirse.',
    ]);

    $segunda->assertValidResponse(409);
    $segunda->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(IncidentFixtures::stored($incidente)->resolution_note)
        ->toBe('La primera nota, que es la que vale.')
        // Y solo se observo una vez: el histograma no cuenta el intento fallido.
        ->and($metrics->observations())->toHaveCount(1);
})->group('RF-PA-05');

it('exige la nota, tambien al descartar', function (array $cuerpo): void {
    // RF-PA-05 pide un flujo de resolucion **con nota**: sin ella la bandeja se
    // vacia y seis meses despues nadie puede explicar que se hizo (RN-13).
    $escenario = escenarioDeBandeja();
    $incidente = IncidentFixtures::open($escenario['employee']);

    Api::as($escenario['token'])
        ->post('/api/v1/incidents/'.$incidente.'/resolve', $cuerpo)
        ->assertStatus(422);

    expect(IncidentFixtures::stored($incidente)->status)->toBe('open');
})->with([
    'sin nota' => [['outcome' => 'resolved']],
    'nota vacia' => [['outcome' => 'resolved', 'note' => '']],
    'nota de espacios en blanco' => [['outcome' => 'dismissed', 'note' => '     ']],
    'nota de una letra' => [['outcome' => 'dismissed', 'note' => 'x']],
])->group('RF-PA-05', 'RN-13');

it('no admite reabrir ni firmar a nombre de otro', function (array $cuerpo): void {
    // `open` no es un desenlace, y el autor sale del token: aceptarlo en el
    // cuerpo permitiria firmar la resolucion de otra persona (RN-13).
    $escenario = escenarioDeBandeja();
    $incidente = IncidentFixtures::open($escenario['employee']);

    Api::as($escenario['token'])
        ->post('/api/v1/incidents/'.$incidente.'/resolve', $cuerpo)
        ->assertStatus(422);
})->with([
    'reabrir' => [['outcome' => 'open', 'note' => 'Vuelvo a dejarla abierta.']],
    'desenlace inventado' => [['outcome' => 'archived', 'note' => 'Una nota cualquiera.']],
    'firmar por otro' => [[
        'outcome' => 'resolved',
        'note' => 'Una nota cualquiera.',
        'resolved_by_user_id' => 1,
    ]],
])->group('RF-PA-05', 'RN-13');

it('devuelve 404 y no 403 cuando la incidencia no existe', function (): void {
    // Quien se equivoca de identificador tiene que recibir «eso no existe» y no
    // un asiento de intento fuera de alcance a nombre de nadie.
    $escenario = escenarioDeBandeja();

    $respuesta = Api::as($escenario['token'])->post('/api/v1/incidents/999999/resolve', [
        'outcome' => 'resolved',
        'note' => 'Una nota cualquiera.',
    ]);

    $respuesta->assertValidResponse(404);
    $respuesta->assertJsonPath('type', 'urn:kronoqr:problem:not-found');
})->group('RF-PA-05');

it('deja constancia de que alguien leyo la bandeja, sin enumerar a nadie', function (): void {
    // RS-05: la bandeja lleva nombre, codigo y departamento de terceros junto a
    // una afirmacion sobre sus horas. Se registra el ALCANCE y jamas lo
    // divulgado (regla dura 21).
    $escenario = escenarioDeBandeja();
    IncidentFixtures::open($escenario['employee']);

    Api::as($escenario['token'])->get('/api/v1/incidents')->assertStatus(200);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::PersonalDataAccessed->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    /** @var object{payload: string} $asiento */
    $payload = json_decode((string) $asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray(['dataset' => 'incident_board'])
        // Sin filtro por persona, el asiento no nombra a nadie: enumerar a los
        // afectados seria una segunda copia de la bandeja con cuatro años de
        // retencion.
        ->and((string) $asiento->payload)->not->toContain($escenario['employee'])
        ->and((string) $asiento->payload)->not->toContain('Amrani');
})->group('RF-PA-05', 'RS-05');
