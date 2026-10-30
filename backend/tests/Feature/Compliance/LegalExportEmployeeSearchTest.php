<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/reports/legal-export/employees/search` (RF-IN-05, V21-3-PA-1,
 * ADR-037 enmienda del 18-10-2026).
 *
 * La busqueda con la que el auditor —y RRHH— encuentra a la persona que nombra
 * un requerimiento de la Inspeccion, por su nombre o su DNI, sin abrir el
 * directorio de plantilla. Se prueba lo que la hace minima: tres caracteres,
 * diez personas, codigo y documento exactos, campos minimos, y un asiento por
 * busqueda que nunca lleva el termino.
 *
 * La autorizacion negativa, rol a rol, esta en
 * `LegalExportEmployeeSearchAuthorizationTest`.
 */

uses(RefreshDatabase::class);

const LEGAL_EXPORT_EMPLOYEE_SEARCH_URI = '/api/v1/reports/legal-export/employees/search';

/** El documento de la persona de referencia, en su forma canonica. */
const LEGAL_EXPORT_EMPLOYEE_SEARCH_DNI = '12345678Z';

/** Su codigo de empleado: opaco, como los que genera el servidor. */
const LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE = 'E7QK2MXPR';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Una plantilla con una persona de referencia —tildes, codigo y DNI—, otra con
 * el mismo nombre sin tildes, una de baja y una suspendida.
 *
 * @return array{token: string, jose: string, homonimo: string, baja: string, suspendida: string, department: int}
 */
function plantillaDeLaBusquedaLegal(UserRole $role = UserRole::AUDITOR): array
{
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site, 'Recepcion');

    $jose = WorkforceFixtures::employee($site, $department, 'active', 'José', 'Pérez Amrani', LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE);
    $homonimo = WorkforceFixtures::employee($site, null, 'active', 'Jose', 'Perez Amrani');
    $baja = WorkforceFixtures::employee($site, $department, 'terminated', 'Lucía', 'Amrani Baja');
    $suspendida = WorkforceFixtures::employee($site, $department, 'suspended', 'Marta', 'Amrani Suspendida');

    DB::update(
        'UPDATE employees SET national_id_hash = digest(?, ?) WHERE uuid = ?',
        [LEGAL_EXPORT_EMPLOYEE_SEARCH_DNI, 'sha256', $jose],
    );

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole($role)),
        'jose' => $jose,
        'homonimo' => $homonimo,
        'baja' => $baja,
        'suspendida' => $suspendida,
        'department' => $department,
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function buscarPersonaLegal(string $token, array $body): TestResponse
{
    return Api::as($token)->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_URI, $body);
}

/**
 * Los `uuid` de la respuesta, en su orden.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function uuidsDeLaBusquedaLegal(TestResponse $response): array
{
    /** @var list<array{uuid: string}> $data */
    $data = $response->json('data');

    return array_map(static fn (array $row): string => $row['uuid'], $data);
}

/**
 * Los asientos de la busqueda, con el payload ya decodificado.
 *
 * @return list<array{actor_type: string, payload: array<string, mixed>, raw: string}>
 */
function asientosDeLaBusquedaLegal(): array
{
    $asientos = [];

    foreach (DB::table('audit_log')->where('action', 'personal_data.accessed')->orderBy('id')->get() as $row) {
        /** @var object{actor_type: string, payload: string} $row */
        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        if (($payload['dataset'] ?? null) === 'legal_export_employee_search') {
            $asientos[] = ['actor_type' => $row->actor_type, 'payload' => $payload, 'raw' => $row->payload];
        }
    }

    return $asientos;
}

it('encuentra por el nombre con o sin tilde y sin distinguir mayusculas', function (string $termino): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    $response = buscarPersonaLegal($plantilla['token'], ['q' => $termino])
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(uuidsDeLaBusquedaLegal($response))->toEqualCanonicalizing([$plantilla['jose'], $plantilla['homonimo']])
        ->and($response->json('truncated'))->toBeFalse();
})->with([
    'con tildes' => ['José Pérez'],
    'sin tildes' => ['jose perez'],
    'en mayusculas' => ['PÉREZ'],
    'solo el nombre completo de corrido' => ['jose perez amrani'],
])->group('RF-IN-05');

it('incluye a las personas de baja y suspendidas, ordenadas por apellidos', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    $response = buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertValidResponse(200);

    // Amrani Baja, Amrani Suspendida, Perez Amrani (Jose), Pérez Amrani (José):
    // el orden es el de la base (`last_name`, `first_name`, `uuid`) y las dos
    // primeras son las que el directorio activo no enseñaria.
    expect(uuidsDeLaBusquedaLegal($response))->toHaveCount(4)
        ->and(array_slice(uuidsDeLaBusquedaLegal($response), 0, 2))->toBe([$plantilla['baja'], $plantilla['suspendida']]);

    /** @var list<array{uuid: string, status: string, terminated_at: string|null}> $data */
    $data = $response->json('data');

    expect($data[0]['status'])->toBe('terminated')
        ->and($data[0]['terminated_at'])->toBe('2026-06-30')
        ->and($data[1]['status'])->toBe('suspended');
})->group('RF-IN-05', 'RL-02');

it('devuelve solo los campos minimos: ni el codigo ni el documento', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    $response = buscarPersonaLegal($plantilla['token'], ['national_id' => LEGAL_EXPORT_EMPLOYEE_SEARCH_DNI])
        ->assertValidResponse(200);

    /** @var list<array<string, mixed>> $data */
    $data = $response->json('data');

    expect($data)->toHaveCount(1)
        ->and(array_keys($data[0]))->toBe(['uuid', 'first_name', 'last_name', 'department', 'status', 'hired_at', 'terminated_at', 'employment_periods'])
        ->and($data[0]['department'])->toMatchArray(['id' => $plantilla['department']]);

    foreach ([LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE, LEGAL_EXPORT_EMPLOYEE_SEARCH_DNI, 'employee_code', 'national_id', 'email'] as $prohibido) {
        expect((string) $response->getContent())->not->toContain($prohibido);
    }
})->group('RF-IN-05', 'RL-08');

it('devuelve el departamento nulo a quien no tiene ninguno', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    $response = buscarPersonaLegal($plantilla['token'], ['q' => 'Jose Perez'])->assertValidResponse(200);

    /** @var list<array{uuid: string, department: mixed}> $data */
    $data = $response->json('data');
    $homonimo = array_values(array_filter($data, static fn (array $row): bool => $row['uuid'] === $plantilla['homonimo']));

    expect($homonimo)->toHaveCount(1)
        ->and($homonimo[0]['department'])->toBeNull();
})->group('RF-IN-05');

it('busca % y _ como los caracteres que son, no como comodines', function (): void {
    $site = WorkforceFixtures::site();
    $conPorcentaje = WorkforceFixtures::employee($site, null, 'active', 'Ana', 'Al%Sur');
    WorkforceFixtures::employee($site, null, 'active', 'Ana', 'AlbaSur');
    $conGuion = WorkforceFixtures::employee($site, null, 'active', 'Ana', 'Ruiz_Gil');
    WorkforceFixtures::employee($site, null, 'active', 'Ana', 'RuizXGil');
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    // Sin escapar, `l%S` casaria tambien con «AlbaSur» y `z_G` con «RuizXGil».
    expect(uuidsDeLaBusquedaLegal(buscarPersonaLegal($token, ['q' => 'l%S'])->assertValidResponse(200)))
        ->toBe([$conPorcentaje])
        ->and(uuidsDeLaBusquedaLegal(buscarPersonaLegal($token, ['q' => 'z_G'])->assertValidResponse(200)))
        ->toBe([$conGuion])
        // Y tres comodines no son un termino de tres caracteres que lo casa todo.
        ->and(uuidsDeLaBusquedaLegal(buscarPersonaLegal($token, ['q' => '%%%'])->assertValidResponse(200)))
        ->toBe([]);
})->group('RF-IN-05');

it('no deja que unaccent convierta en comodines los de ancho completo', function (string $termino): void {
    // `unaccent.rules` traduce ％ (U+FF05), ﹪ (U+FE6A), ＿ (U+FF3F), ＼ (U+FF3C),
    // ﹨ (U+FE68) y ∖ (U+2216) a `%`, `_` y `\` ASCII. Escapados antes de
    // normalizar llegaban activos al `ILIKE`: `％＿＿` casaba con cualquier
    // nombre de dos letras o mas y saltaba el minimo de tres caracteres.
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], ['q' => $termino])
        ->assertValidResponse(200)
        ->assertExactJson(['data' => [], 'truncated' => false]);

    $payload = asientosDeLaBusquedaLegal()[0]['payload'] ?? [];

    expect($payload)->toMatchArray(['record_count' => 0])
        ->and(array_key_exists('employee_uuids', $payload))->toBeFalse();
})->with([
    'tres porcentajes de ancho completo' => ['％％％'],
    'tres subrayados de ancho completo' => ['＿＿＿'],
    'una letra y dos subrayados' => ['a＿＿'],
    'porcentajes alrededor de una letra' => ['％x％'],
    'porcentaje pequeño' => ['﹪﹪﹪'],
    'barras invertidas de ancho completo y pequeña' => ['＼﹨∖'],
])->group('RF-IN-05', 'RS-05');

it('encuentra con los apellidos delante del nombre, con coma o sin ella', function (string $termino): void {
    // Es como nombra a la persona un requerimiento de la Inspeccion o una nomina.
    $plantilla = plantillaDeLaBusquedaLegal();

    expect(uuidsDeLaBusquedaLegal(buscarPersonaLegal($plantilla['token'], ['q' => $termino])->assertValidResponse(200)))
        ->toEqualCanonicalizing([$plantilla['jose'], $plantilla['homonimo']]);
})->with([
    'con coma' => ['Pérez Amrani, José'],
    'sin coma' => ['Pérez Amrani José'],
    'con coma y sin espacio' => ['perez amrani,jose'],
])->group('RF-IN-05');

it('no convierte en un patron vacio un termino que solo son comas', function (): void {
    // `,,,` tiene tres caracteres y pasa el minimo; sin la coma no queda nada, y
    // un patron vacio casaria con la plantilla entera.
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], ['q' => ',,,'])
        ->assertValidResponse(200)
        ->assertExactJson(['data' => [], 'truncated' => false]);
})->group('RF-IN-05', 'RS-05');

it('devuelve como mucho diez personas y avisa de que habia mas', function (): void {
    $site = WorkforceFixtures::site();
    $uuids = [];

    foreach (range(1, 12) as $n) {
        $uuids[sprintf('Persona %02d', $n)] = WorkforceFixtures::employee($site, null, 'active', sprintf('Persona %02d', $n), 'Repetido');
    }

    ksort($uuids);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    $response = buscarPersonaLegal($token, ['q' => 'repetido'])->assertValidResponse(200);

    // Las diez primeras por apellidos y nombre: el recorte es estable.
    expect(uuidsDeLaBusquedaLegal($response))->toBe(array_slice(array_values($uuids), 0, 10))
        ->and($response->json('truncated'))->toBeTrue();
})->group('RF-IN-05');

it('no avisa de recorte cuando caben todas', function (): void {
    $site = WorkforceFixtures::site();

    foreach (range(1, 10) as $n) {
        WorkforceFixtures::employee($site, null, 'active', 'Persona '.$n, 'Justo');
    }

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    $response = buscarPersonaLegal($token, ['q' => 'justo'])->assertValidResponse(200);

    expect(uuidsDeLaBusquedaLegal($response))->toHaveCount(10)
        ->and($response->json('truncated'))->toBeFalse();
})->group('RF-IN-05');

it('encuentra por el codigo exacto y nunca por un prefijo', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    expect(uuidsDeLaBusquedaLegal(buscarPersonaLegal($plantilla['token'], ['q' => LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE])->assertValidResponse(200)))
        ->toBe([$plantilla['jose']])
        // `citext`: el codigo no distingue mayusculas, pero sigue siendo exacto.
        ->and(uuidsDeLaBusquedaLegal(buscarPersonaLegal($plantilla['token'], ['q' => strtolower(LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE)])->assertValidResponse(200)))
        ->toBe([$plantilla['jose']])
        ->and(uuidsDeLaBusquedaLegal(buscarPersonaLegal($plantilla['token'], ['q' => 'E7QK2'])->assertValidResponse(200)))
        ->toBe([])
        ->and(uuidsDeLaBusquedaLegal(buscarPersonaLegal($plantilla['token'], ['q' => 'QK2MXP'])->assertValidResponse(200)))
        ->toBe([]);
})->group('RF-IN-05');

it('encuentra por el DNI escrito de cualquier forma, exacto contra su hash', function (string $documento): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    $response = buscarPersonaLegal($plantilla['token'], ['national_id' => $documento])->assertValidResponse(200);

    expect(uuidsDeLaBusquedaLegal($response))->toBe([$plantilla['jose']])
        ->and($response->json('truncated'))->toBeFalse();
})->with([
    'canonico' => [LEGAL_EXPORT_EMPLOYEE_SEARCH_DNI],
    'con puntos, guion y minuscula' => ['12.345.678-z'],
    'con espacios' => [' 12345678 z '],
])->group('RF-IN-05', 'RL-08');

it('no admite coincidencias parciales del DNI', function (string $documento): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], ['national_id' => $documento])
        ->assertValidResponse(200)
        ->assertExactJson(['data' => [], 'truncated' => false]);
})->with([
    'sin la letra' => ['12345678'],
    'el final' => ['5678Z'],
    'otra letra' => ['12345678Y'],
])->group('RF-IN-05', 'RL-08');

it('responde 200 vacio y no 404 cuando no casa nadie', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], ['q' => 'nadie se llama asi'])
        ->assertValidResponse(200)
        ->assertExactJson(['data' => [], 'truncated' => false]);
})->group('RF-IN-05');

it('rechaza con 422 lo que no es una busqueda valida', function (array $cuerpo, string $campo): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], $cuerpo)
        ->assertValidResponse(422)
        ->assertJsonValidationErrors([$campo]);

    // Y un 422 no consulta nada ni deja asiento.
    expect(asientosDeLaBusquedaLegal())->toBe([]);
})->with([
    'sin cuerpo' => [[], 'q'],
    'los dos campos a la vez' => [['q' => 'amrani', 'national_id' => LEGAL_EXPORT_EMPLOYEE_SEARCH_DNI], 'q'],
    'un campo desconocido' => [['q' => 'amrani', 'employee_code' => 'E1'], 'employee_code'],
    'dos letras' => [['q' => 'am'], 'q'],
    'tres espacios' => [['q' => '   '], 'q'],
    'dos letras entre espacios' => [['q' => '  am  '], 'q'],
    // `trim()` no los quita: sin el recorte Unicode serian tres caracteres.
    'tres espacios ideograficos' => [['q' => "\u{3000}\u{3000}\u{3000}"], 'q'],
    'dos letras entre espacios no separables' => [['q' => "\u{00A0}am\u{00A0}"], 'q'],
    'un termino de mas de cien' => [['q' => str_repeat('a', 101)], 'q'],
    'un termino que no es texto' => [['q' => ['amrani']], 'q'],
    'un documento que normalizado se queda corto' => [['national_id' => '1-2-3'], 'national_id'],
    'un documento de solo separadores' => [['national_id' => '....'], 'national_id'],
])->group('RF-IN-05');

it('no repite en el 422 el termino recibido', function (): void {
    // El cuerpo es un nombre o un DNI, y un 422 se registra.
    $plantilla = plantillaDeLaBusquedaLegal();

    $response = buscarPersonaLegal($plantilla['token'], ['national_id' => '9-8-7'])->assertStatus(422);

    expect((string) $response->getContent())->not->toContain('9-8-7')
        ->and((string) $response->getContent())->not->toContain('987');
})->group('RF-IN-05', 'RS-05');

it('deja un asiento por busqueda con el criterio y el recuento, tambien sin resultados', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();
    buscarPersonaLegal($plantilla['token'], ['national_id' => '12.345.678-z'])->assertOk();
    buscarPersonaLegal($plantilla['token'], ['national_id' => '99999999R'])->assertOk();
    // La misma busqueda repetida es otro acto: sin agrupar por ventana.
    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();

    $asientos = asientosDeLaBusquedaLegal();

    // Exactamente estas cinco claves y ninguna mas: ni el termino ni nada de
    // la respuesta salvo a quien se devolvio. Ordenadas, porque JSONB no
    // conserva el orden.
    $primero = $asientos[0]['payload'] ?? [];
    ksort($primero);

    // Las personas que vio, en el orden en que se le enseñaron: lo que RL-15
    // obliga a poder contestar cuando una de ellas pregunta quien la consulto.
    // Se piden fuera del techo: la quinta busqueda no deja de ser una mas.
    $devueltas = uuidsDeLaBusquedaLegal(buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk());

    expect($asientos)->toHaveCount(4)
        ->and($asientos[0]['actor_type'])->toBe('user')
        ->and($primero)->toBe([
            'criterion' => 'name_or_code',
            'dataset' => 'legal_export_employee_search',
            'employee_uuids' => implode(',', $devueltas),
            'record_count' => 4,
            'truncated' => false,
        ])
        ->and($asientos[1]['payload'])->toMatchArray([
            'criterion' => 'national_id',
            'record_count' => 1,
            'truncated' => false,
            'employee_uuids' => $plantilla['jose'],
        ])
        // Probar documentos hasta acertar es justo lo que el trail tiene que
        // poder enseñar (ADR-037). Sin nadie devuelto, sin la clave.
        ->and($asientos[2]['payload'])->toMatchArray(['criterion' => 'national_id', 'record_count' => 0, 'truncated' => false])
        ->and($asientos[2]['payload'])->not->toHaveKey('employee_uuids')
        ->and($asientos[3]['payload'])->toMatchArray(['criterion' => 'name_or_code', 'record_count' => 4]);
})->group('RF-IN-05', 'RS-05', 'RL-15');

it('enumera en el asiento solo las diez personas devueltas, no la que sobra', function (): void {
    $site = WorkforceFixtures::site();

    foreach (range(1, 11) as $n) {
        WorkforceFixtures::employee($site, null, 'active', sprintf('Persona %02d', $n), 'Repetido');
    }

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));
    $devueltas = uuidsDeLaBusquedaLegal(buscarPersonaLegal($token, ['q' => 'repetido'])->assertOk());
    $payload = asientosDeLaBusquedaLegal()[0]['payload'] ?? [];
    $enElAsiento = explode(',', \is_string($payload['employee_uuids'] ?? null) ? $payload['employee_uuids'] : '');

    // La undecima solo sirve para saber que habia mas: no se le enseño a nadie.
    expect($devueltas)->toHaveCount(10)
        ->and($enElAsiento)->toBe($devueltas);
})->group('RF-IN-05', 'RS-05', 'RL-15');

it('marca en el asiento que la lista se recorto', function (): void {
    $site = WorkforceFixtures::site();

    foreach (range(1, 11) as $n) {
        WorkforceFixtures::employee($site, null, 'active', 'Persona '.$n, 'Repetido');
    }

    buscarPersonaLegal(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)), ['q' => 'repetido'])->assertOk();

    expect(asientosDeLaBusquedaLegal()[0]['payload'])
        ->toMatchArray(['criterion' => 'name_or_code', 'record_count' => 10, 'truncated' => true]);
})->group('RF-IN-05', 'RS-05');

it('nunca escribe en el asiento el termino buscado', function (): void {
    // Regla dura 21 y RL-08: un nombre o un DNI en `audit_log` serian el dato
    // personal que el asiento existe para no copiar.
    $plantilla = plantillaDeLaBusquedaLegal();

    buscarPersonaLegal($plantilla['token'], ['q' => 'Amrani'])->assertOk();
    buscarPersonaLegal($plantilla['token'], ['q' => LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE])->assertOk();
    buscarPersonaLegal($plantilla['token'], ['national_id' => '12.345.678-z'])->assertOk();

    $crudo = implode("\n", array_map(static fn (array $a): string => $a['raw'], asientosDeLaBusquedaLegal()));

    expect(asientosDeLaBusquedaLegal())->toHaveCount(3);

    // El `uuid` de quien se devolvio si esta (`employee_uuids`); lo que no esta
    // nunca es lo que se tecleo, ni el nombre de nadie.
    foreach (['amrani', strtolower(LEGAL_EXPORT_EMPLOYEE_SEARCH_CODE), '12345678', '12.345.678', 'josé', 'jose', 'pérez', 'perez'] as $prohibido) {
        expect(mb_strtolower($crudo))->not->toContain($prohibido);
    }
})->group('RF-IN-05', 'RS-05', 'RL-08');

it('corta con 429 la peticion numero once dentro del minuto', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();

    foreach (range(1, 10) as $_) {
        buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();
    }

    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])
        ->assertValidResponse(429)
        ->assertHeader('Retry-After');

    // El limitador contesta antes que la busqueda: la undecima no deja asiento.
    expect(asientosDeLaBusquedaLegal())->toHaveCount(10);
})->group('RF-IN-05', 'RS-02', 'RS-05');

it('cuenta por cuenta: el techo de una no gasta el de otra', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();
    config()->set('compliance.legal_export_search.per_minute', 2);

    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();
    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();
    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertStatus(429);

    $otra = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    buscarPersonaLegal($otra, ['q' => 'amrani'])->assertOk();
})->group('RF-IN-05', 'RS-02');

it('aplica tambien el techo por hora, configurable', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();
    config()->set('compliance.legal_export_search.per_hour', 3);

    foreach (range(1, 3) as $_) {
        buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();
    }

    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertStatus(429);
})->group('RF-IN-05', 'RS-02');

it('aplica tambien el techo por origen, que suma todas las cuentas', function (): void {
    $plantilla = plantillaDeLaBusquedaLegal();
    config()->set('compliance.legal_export_search.per_ip_per_minute', 2);

    $otra = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    buscarPersonaLegal($plantilla['token'], ['q' => 'amrani'])->assertOk();
    buscarPersonaLegal($otra, ['q' => 'amrani'])->assertOk();
    // Cada cuenta lleva una sola, pero las dos salen por la misma IP.
    buscarPersonaLegal($otra, ['q' => 'amrani'])->assertStatus(429);
})->group('RF-IN-05', 'RS-02');

it('trae de serie diez por minuto y sesenta por hora por cuenta, y ciento veinte por origen', function (): void {
    expect(config('compliance.legal_export_search.per_minute'))->toBe(10)
        ->and(config('compliance.legal_export_search.per_hour'))->toBe(60)
        ->and(config('compliance.legal_export_search.per_ip_per_minute'))->toBe(120);
})->group('RF-IN-05', 'RS-02');

it('lleva una sola zona de limite: la suya y no la de gestion', function (): void {
    // Dos zonas eran dos sitios donde mirar por que una peticion recibio `429`;
    // la propia ya trae el eje por origen.
    $route = Route::getRoutes()->getByName('compliance.reports.legal-export.employees.search');

    expect($route)->toBeInstanceOf(Illuminate\Routing\Route::class);

    $throttles = array_values(array_filter(
        $route instanceof Illuminate\Routing\Route ? $route->gatherMiddleware() : [],
        static fn (mixed $middleware): bool => \is_string($middleware) && str_starts_with($middleware, 'throttle:'),
    ));

    expect($throttles)->toBe(['throttle:legal-export-search']);
})->group('RF-IN-05', 'RS-02');

it('devuelve todos los periodos de alta de una persona readmitida, y no solo el ultimo', function (): void {
    // RN-27, ADR-062: hired_at y terminated_at son los del periodo en curso; los
    // anteriores viajan en employment_periods para no engañar a quien exporta.
    $plantilla = plantillaDeLaBusquedaLegal();
    $id = DB::table('employees')->where('uuid', $plantilla['baja'])->value('id');

    // Lucia, de baja el 30 de junio de 2026, vuelve el 1 de septiembre.
    DB::update("UPDATE employees SET status = 'active', hired_at = '2026-09-01', terminated_at = NULL WHERE id = ?", [$id]);
    DB::insert("INSERT INTO employment_periods (uuid, employee_id, hired_on) VALUES (gen_random_uuid(), ?, '2026-09-01')", [$id]);

    buscarPersonaLegal($plantilla['token'], ['q' => 'Amrani Baja'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.uuid', $plantilla['baja'])
        ->assertJsonPath('data.0.hired_at', '2026-09-01')
        ->assertJsonPath('data.0.terminated_at', null)
        ->assertJsonCount(2, 'data.0.employment_periods')
        ->assertJsonPath('data.0.employment_periods.0.hired_at', '2026-01-01')
        ->assertJsonPath('data.0.employment_periods.0.terminated_at', '2026-06-30')
        ->assertJsonPath('data.0.employment_periods.0.state', 'terminated')
        ->assertJsonPath('data.0.employment_periods.1.hired_at', '2026-09-01')
        ->assertJsonPath('data.0.employment_periods.1.state', 'active');
})->group('RF-IN-05', 'RN-27');
