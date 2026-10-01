<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La marca de teletrabajo de la ficha (RF-GP-01, decision comercial de la
 * 2.1.0): alta, modificacion, listado con filtro e importacion, cada respuesta
 * validada contra el contrato con Spectator, con su asiento en `audit_log` y
 * con la autorizacion negativa de quien no puede cambiarla.
 *
 * Que la marca no cambie el fichaje ni ningun calculo lo prueba
 * `TeleworkingIsInformativeTest`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-09-14 10:00:00');
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, user: int, site: int, department: int}
 */
function teletrabajoContexto(): array
{
    $site = WorkforceFixtures::site();
    $user = ManagementUsers::withRole(UserRole::RRHH);

    return [
        'token' => ManagementUsers::tokenFor($user),
        'user' => $user->id,
        'site' => $site,
        'department' => WorkforceFixtures::department($site),
    ];
}

/**
 * Los asientos de una accion, con el payload decodificado.
 *
 * @return list<array{actor_id: int|null, payload: array<string, mixed>}>
 */
function teletrabajoAsientos(string $action): array
{
    return array_values(DB::table('audit_log')
        ->where('action', $action)
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): array {
            /** @var object{actor_id: int|null, payload: string} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return ['actor_id' => $row->actor_id, 'payload' => $payload];
        })
        ->all());
}

function teletrabajoMarca(string $uuid): mixed
{
    return DB::table('employees')->where('uuid', $uuid)->value('teleworking');
}

// -----------------------------------------------------------------------------
// Alta
// -----------------------------------------------------------------------------

it('da de alta sin teletrabajo si no se indica, y lo devuelve en la ficha', function (): void {
    $contexto = teletrabajoContexto();

    $respuesta = Api::as($contexto['token'])->post('/api/v1/employees', [
        'first_name' => 'Ana',
        'last_name' => 'Soler',
        'hired_at' => '2026-09-14',
    ])->assertValidRequest()->assertValidResponse(201)
        ->assertJsonPath('employee.teleworking', false);

    $uuid = $respuesta->json('employee.uuid');

    expect(\is_string($uuid) ? teletrabajoMarca($uuid) : null)->toBeFalse()
        ->and(teletrabajoAsientos('employee.hired')[0]['payload']['teleworking'] ?? null)->toBeFalse();
})->group('RF-GP-01', 'RL-04');

it('da de alta con teletrabajo y deja el valor inicial en el asiento del alta', function (): void {
    $contexto = teletrabajoContexto();

    $uuid = Api::as($contexto['token'])->post('/api/v1/employees', [
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'department_id' => $contexto['department'],
        'hired_at' => '2026-09-14',
        'teleworking' => true,
    ])->assertValidRequest()->assertValidResponse(201)
        ->assertJsonPath('employee.teleworking', true)
        ->json('employee.uuid');

    $asientos = teletrabajoAsientos('employee.hired');

    expect(\is_string($uuid) ? teletrabajoMarca($uuid) : null)->toBeTrue()
        ->and($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_id'])->toBe($contexto['user'])
        ->and($asientos[0]['payload'])->toEqual([
            'department_id' => $contexto['department'],
            'employee_uuid' => $uuid,
            'site_id' => $contexto['site'],
            'teleworking' => true,
            'via_import' => false,
        ])
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RF-GP-01', 'RL-04', 'RS-07');

it('rechaza un teletrabajo que no es booleano en el alta', function (): void {
    $contexto = teletrabajoContexto();

    Api::as($contexto['token'])->post('/api/v1/employees', [
        'first_name' => 'Ana',
        'last_name' => 'Soler',
        'hired_at' => '2026-09-14',
        'teleworking' => 'quizas',
    ])->assertStatus(422);

    expect(DB::table('employees')->count())->toBe(0);
})->group('RF-GP-01');

// -----------------------------------------------------------------------------
// Modificacion
// -----------------------------------------------------------------------------

it('marca y desmarca el teletrabajo con un asiento por cambio y la cadena integra', function (): void {
    $contexto = teletrabajoContexto();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['teleworking' => true])
        ->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('teleworking', true);

    expect(teletrabajoMarca($uuid))->toBeTrue();

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['teleworking' => false])
        ->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('teleworking', false);

    $asientos = teletrabajoAsientos('employee.updated');

    expect(teletrabajoMarca($uuid))->toBeFalse()
        ->and($asientos)->toHaveCount(2)
        // El nombre del campo y ningun valor (AUD-2, regla dura 21).
        ->and($asientos[0]['payload'])->toEqual(['changed_fields' => ['teleworking'], 'employee_uuid' => $uuid])
        ->and($asientos[1]['payload'])->toEqual(['changed_fields' => ['teleworking'], 'employee_uuid' => $uuid])
        ->and($asientos[0]['actor_id'])->toBe($contexto['user'])
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RF-GP-01', 'RL-04', 'RS-07');

it('no deja asiento si la marca ya tenia ese valor', function (): void {
    $contexto = teletrabajoContexto();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['teleworking' => false])
        ->assertValidResponse(200);

    expect(teletrabajoAsientos('employee.updated'))->toHaveCount(0);
})->group('RF-GP-01', 'RL-04');

it('lista el teletrabajo junto a los demas campos tocados', function (): void {
    $contexto = teletrabajoContexto();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['locale' => 'en', 'teleworking' => true])
        ->assertValidResponse(200);

    expect(teletrabajoAsientos('employee.updated')[0]['payload']['changed_fields'] ?? null)
        ->toBe(['locale', 'teleworking']);
})->group('RF-GP-01', 'RL-04');

it('rechaza un teletrabajo nulo en la modificacion', function (): void {
    // No es anulable: no hay un tercer estado que signifique algo.
    $contexto = teletrabajoContexto();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['teleworking' => null])
        ->assertStatus(422);

    expect(teletrabajoMarca($uuid))->toBeFalse()
        ->and(teletrabajoAsientos('employee.updated'))->toHaveCount(0);
})->group('RF-GP-01');

it('no deja cambiar la marca a quien no puede modificar la plantilla', function (UserRole $role): void {
    // Regla dura 18: el campo nuevo viaja por el mismo `PATCH` y hereda su
    // policy («rrhh+»). Se comprueba con el cuerpo que lo cambia, no con otro.
    $contexto = teletrabajoContexto();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    Api::as($token)->patch('/api/v1/employees/'.$uuid, ['teleworking' => true])->assertStatus(403);
    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Ana',
        'last_name' => 'Soler',
        'hired_at' => '2026-09-14',
        'teleworking' => true,
    ])->assertStatus(403);

    expect(teletrabajoMarca($uuid))->toBeFalse()
        ->and(DB::table('employees')->count())->toBe(1)
        ->and(teletrabajoAsientos('employee.updated'))->toHaveCount(0);
})->with([
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
])->group('RF-GP-01', 'RQ-07');

it('no deja cambiar la marca con un token de quiosco', function (): void {
    $contexto = teletrabajoContexto();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as(ManagementUsers::kioskToken())->patch('/api/v1/employees/'.$uuid, ['teleworking' => true])
        ->assertStatus(403);

    expect(teletrabajoMarca($uuid))->toBeFalse();
})->group('RF-GP-01', 'RQ-07');

// -----------------------------------------------------------------------------
// Listado
// -----------------------------------------------------------------------------

it('devuelve la marca en cada fila y filtra por ella en el servidor', function (): void {
    $contexto = teletrabajoContexto();
    $remota = WorkforceFixtures::employee($contexto['site'], $contexto['department'], lastName: 'Remota');
    WorkforceFixtures::employee($contexto['site'], $contexto['department'], lastName: 'Presencial A');
    WorkforceFixtures::employee($contexto['site'], $contexto['department'], lastName: 'Presencial B');

    DB::table('employees')->where('uuid', $remota)->update(['teleworking' => true]);

    Api::as($contexto['token'])->get('/api/v1/employees')
        ->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.teleworking', false)
        ->assertJsonPath('data.2.uuid', $remota)
        ->assertJsonPath('data.2.teleworking', true);

    // El literal `true`/`false` es la serializacion que genera el cliente.
    Api::as($contexto['token'])->get('/api/v1/employees', ['teleworking' => 'true', 'per_page' => 1])
        ->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.uuid', $remota);

    Api::as($contexto['token'])->get('/api/v1/employees', ['teleworking' => 'false'])
        ->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('meta.total', 2);
})->group('RF-GP-01');

it('rechaza un filtro de teletrabajo que no es booleano', function (): void {
    $contexto = teletrabajoContexto();

    Api::as($contexto['token'])->get('/api/v1/employees', ['teleworking' => 'quizas'])
        ->assertStatus(422);
})->group('RF-GP-01');

it('anota el filtro de teletrabajo en el alcance del asiento de divulgacion', function (): void {
    $contexto = teletrabajoContexto();
    WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->get('/api/v1/employees', ['teleworking' => 'false'])->assertValidResponse(200);
    Api::as($contexto['token'])->get('/api/v1/employees')->assertValidResponse(200);

    $asientos = teletrabajoAsientos('personal_data.accessed');

    expect($asientos)->toHaveCount(2)
        ->and($asientos[0]['payload'])->toMatchArray(['dataset' => 'employee_directory', 'teleworking' => 'false'])
        ->and($asientos[1]['payload'])->toMatchArray(['teleworking' => 'any']);
})->group('RF-GP-01', 'RS-05');

it('no deja filtrar por teletrabajo a quien no puede listar la plantilla', function (): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)))
        ->get('/api/v1/employees', ['teleworking' => 'true'])
        ->assertStatus(403);

    Api::as(ManagementUsers::kioskToken())
        ->get('/api/v1/employees', ['teleworking' => 'true'])
        ->assertStatus(403);
})->group('RF-GP-01', 'RQ-07');

// -----------------------------------------------------------------------------
// Importacion
// -----------------------------------------------------------------------------

function teletrabajoImportar(string $token, string $csv): void
{
    $validacion = Api::as($token)->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => ImportFiles::csv($csv)])
        ->assertValidResponse(200);

    $checksum = $validacion->json('file.sha256');

    Api::as($token)->upload('/api/v1/employees/import', [
        'mode' => 'apply',
        'confirm_checksum' => \is_string($checksum) ? $checksum : '',
    ], ['file' => ImportFiles::csv($csv)])->assertValidResponse(200);
}

it('la importacion da de alta sin teletrabajo aunque el fichero traiga la columna', function (): void {
    // La importacion no lee la marca: una columna «teletrabajo» es una columna
    // desconocida, se avisa en el informe y no se aplica.
    $contexto = teletrabajoContexto();

    teletrabajoImportar($contexto['token'], "nombre,apellidos,dni,fecha_alta,teletrabajo\nMarta,Vidal,87654321X,2026-02-01,si\n");

    expect(DB::table('employees')->count())->toBe(1)
        ->and(DB::table('employees')->value('teleworking'))->toBeFalse()
        ->and(teletrabajoAsientos('employee.hired')[0]['payload']['teleworking'] ?? null)->toBeFalse();
})->group('RF-GP-01', 'RF-GP-05');

it('la reimportacion conserva la marca de quien ya estaba marcado', function (): void {
    $contexto = teletrabajoContexto();

    $uuid = Api::as($contexto['token'])->post('/api/v1/employees', [
        'first_name' => 'Marta',
        'last_name' => 'Vidal',
        'national_id' => '87654321X',
        'hired_at' => '2026-02-01',
        'teleworking' => true,
    ])->assertValidResponse(201)->json('employee.uuid');

    // Mismo documento, apellido corregido: la linea es una actualizacion.
    teletrabajoImportar($contexto['token'], "nombre,apellidos,dni,fecha_alta\nMarta,Vidal Ruiz,87654321X,2026-02-01\n");

    expect(DB::table('employees')->count())->toBe(1)
        ->and(DB::table('employees')->where('uuid', $uuid)->value('last_name'))->toBe('Vidal Ruiz')
        ->and(\is_string($uuid) ? teletrabajoMarca($uuid) : null)->toBeTrue()
        // El asiento de la modificacion no la nombra: no cambio.
        ->and(teletrabajoAsientos('employee.updated')[0]['payload']['changed_fields'] ?? null)->toBe(['last_name']);
})->group('RF-GP-01', 'RF-GP-05');
