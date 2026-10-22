<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Compliance\AuditPayloads;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/credentials/status` pagina y filtra en el servidor (2.3.0,
 * bloque 14, tarea 14.5; HC1).
 *
 * Hasta la 2.2.1 el tablero devolvia la plantilla entera y el panel filtraba y
 * paginaba en el navegador: cada visita descargaba —y dejaba auditadas como
 * divulgadas— todas las filas para enseñar treinta. Lo que se afirma:
 *
 * - `page` y `per_page` recortan las filas y `meta` dice donde se esta;
 * - `q` encuentra por nombre o codigo sin distinguir acentos ni mayusculas;
 * - `department_id`, `without_department` y `status` acotan con Y logico;
 * - **`summary` sigue siendo la plantilla entera**, filtre lo que se filtre;
 * - el asiento cuenta **las filas servidas** y nunca el termino buscado.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Siete personas: cinco en Recepcion —dos con tarjeta entregada a medias— y
 * dos sin departamento, una de ellas «Lucía García».
 *
 * @return array{token: string, department: int, garcia: string}
 */
function tableroPaginadoContexto(): array
{
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);

    foreach (range(1, 5) as $n) {
        $uuid = WorkforceFixtures::employee($site, $department, lastName: 'Recepcion '.$n);

        if ($n <= 2) {
            $id = DB::table('employees')->where('uuid', $uuid)->value('id');
            Credentials::issueFor(is_numeric($id) ? (int) $id : 0);
        }
    }

    WorkforceFixtures::employee($site, lastName: 'Sin Departamento');
    $garcia = WorkforceFixtures::employee($site, firstName: 'Lucía', lastName: 'García', employeeCode: 'EGARCIA001');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'department' => $department,
        'garcia' => $garcia,
    ];
}

it('pagina la plantilla y deja el resumen entero', function (): void {
    $contexto = tableroPaginadoContexto();

    $primera = Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['per_page' => 3])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 3)
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.total_pages', 3)
        ->assertJsonPath('summary.employees', 7);

    $ultima = Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['per_page' => 3, 'page' => 3])
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data');

    // Sin solapes: la ultima no repite a nadie de la primera.
    expect(array_intersect(
        array_column((array) $primera->json('data'), 'employee_uuid'),
        array_column((array) $ultima->json('data'), 'employee_uuid'),
    ))->toBe([]);
})->group('RF-QR-08');

it('sin per_page sirve las treinta filas de la tabla del panel', function (): void {
    $contexto = tableroPaginadoContexto();

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.per_page', 30)
        ->assertJsonCount(7, 'data');
})->group('RF-QR-08');

it('busca por nombre o codigo sin distinguir acentos ni mayusculas', function (string $termino): void {
    $contexto = tableroPaginadoContexto();

    $response = Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['q' => $termino])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1)
        // El denominador no cambia con la busqueda.
        ->assertJsonPath('summary.employees', 7);

    expect($response->json('data.0.employee_uuid'))->toBe($contexto['garcia']);
})->with(['garcia', 'LUCIA GARC', 'egarcia001'])->group('RF-QR-08');

it('acota por departamento, por la falta de el y por estado, con Y logico', function (array $filtro, int $total): void {
    $contexto = tableroPaginadoContexto();

    $query = array_map(
        static fn (mixed $valor): mixed => $valor === 'DEPARTAMENTO' ? $contexto['department'] : $valor,
        $filtro,
    );

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', $query)
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', $total)
        ->assertJsonPath('summary.employees', 7);
})->with([
    'un departamento' => [['department_id' => 'DEPARTAMENTO'], 5],
    'sin departamento' => [['without_department' => 'true'], 2],
    'sin tarjeta' => [['status' => 'no_credential'], 5],
    'departamento y sin tarjeta' => [['department_id' => 'DEPARTAMENTO', 'status' => 'no_credential'], 3],
    'departamento que no existe' => [['department_id' => 999999], 0],
])->group('RF-QR-08');

it('rechaza filtros o paginas fuera del contrato', function (array $query, string $campo): void {
    $contexto = tableroPaginadoContexto();

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', $query)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor($campo);
})->with([
    'estado inventado' => [['status' => 'lost'], 'status'],
    'mas de cien por pagina' => [['per_page' => 101], 'per_page'],
    'pagina cero' => [['page' => 0], 'page'],
    'departamento y sin departamento a la vez' => [['department_id' => 1, 'without_department' => 'true'], 'department_id'],
    'busqueda demasiado larga' => [['q' => str_repeat('a', 101)], 'q'],
])->group('RF-QR-08');

it('el asiento cuenta las filas servidas y nunca el termino buscado', function (): void {
    $contexto = tableroPaginadoContexto();

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['per_page' => 2, 'page' => 2, 'q' => 'recepcion'])
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->where('action', 'personal_data.accessed')->orderByDesc('id')->value('payload');
    $payload = AuditPayloads::decode($asiento);

    expect($payload)->toMatchArray([
        'dataset' => 'credential_status',
        'record_count' => 2,
        'page' => 2,
        'searched' => true,
    ])
        // Regla dura 21: el termino puede ser un nombre.
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('recepcion');
})->group('RF-QR-08', 'RS-05', 'RL-15');

it('busca como el directorio de plantilla: Ł y ø sin acento, y los comodines como caracteres', function (string $termino, int $total): void {
    // Revision de codigo del bloque 14: el tablero usaba su propia comparacion
    // (NFD sin marcas), que no convierte «Ł» ni «ø». Ahora es `PersonNameSearch`,
    // la de `GET /employees`, las cuentas y la exportacion legal.
    $contexto = tableroPaginadoContexto();
    $site = WorkforceFixtures::onlySiteId();
    WorkforceFixtures::employee($site, firstName: 'Łukasz', lastName: 'Kowalski');
    WorkforceFixtures::employee($site, firstName: 'Søren', lastName: 'Kierkegaard');

    Api::as($contexto['token'])
        ->get('/api/v1/credentials/status', ['q' => $termino])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', $total);
})->with([
    'lukasz' => ['lukasz', 1],
    'soren' => ['soren', 1],
    'Søren con su letra' => ['Søren', 1],
    'un porcentaje no es comodin' => ['%', 0],
    'un subrayado tampoco' => ['_', 0],
])->group('RF-QR-08');
