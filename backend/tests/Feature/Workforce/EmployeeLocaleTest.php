<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El idioma de una ficha es uno de los idiomas ACTIVOS de la instalacion
 * (RF-KI-05, regla dura 13; bloque 26 de la 2.2.1). De serie solo esta activo el
 * español, asi que:
 *
 * - en el alta y en la modificacion, un idioma fuera de `LOCALE_AVAILABLE` es
 *   `422` y sin idioma rige `LOCALE_DEFAULT`, no un `es` escrito en el codigo;
 * - en la importacion, un idioma inactivo NO rechaza la linea: se avisa con
 *   `locale_not_active` y se aplica el de la instalacion.
 *
 * Cada respuesta se valida contra el contrato con Spectator.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-09-14 10:00:00');
    Spectator::using('openapi.yaml');
});

function idiomaFichaToken(): string
{
    WorkforceFixtures::site();

    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function idiomaFichaAlta(array $overrides = []): array
{
    return [
        'first_name' => 'Marta',
        'last_name' => 'Vidal',
        'national_id' => '87654321X',
        'hired_at' => '2026-02-01',
        ...$overrides,
    ];
}

/**
 * Comprueba y aplica el fichero; devuelve el informe de la comprobacion.
 *
 * @return TestResponse<Response>
 */
function idiomaFichaImportar(string $token, string $csv, string $locale = 'es'): TestResponse
{
    $validacion = Api::as($token)
        ->withHeaders(['Accept-Language' => $locale])
        ->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => ImportFiles::csv($csv)])
        ->assertValidResponse(200);

    $checksum = $validacion->json('file.sha256');

    Api::as($token)->upload('/api/v1/employees/import', [
        'mode' => 'apply',
        'confirm_checksum' => \is_string($checksum) ? $checksum : '',
    ], ['file' => ImportFiles::csv($csv)])->assertValidResponse(200);

    return $validacion;
}

// -----------------------------------------------------------------------------
// Alta y modificacion
// -----------------------------------------------------------------------------

it('sin idioma, el alta toma el idioma por defecto de la instalacion', function (string $default, string ...$available): void {
    InstallationLocale::set($default, array_values($available));
    $token = idiomaFichaToken();

    Api::as($token)->post('/api/v1/employees', idiomaFichaAlta())
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee.locale', $default);

    expect(DB::table('employees')->value('locale'))->toBe($default);
})->with([
    'de serie, solo español' => ['es', 'es'],
    'una instalacion en ingles' => ['en', 'en', 'es'],
])->group('RF-KI-05', 'RF-GP-01', 'RF-PD-01');

it('rechaza con 422 un idioma que la instalacion no tiene activo, y no da de alta a nadie', function (): void {
    $token = idiomaFichaToken();

    Api::as($token)->post('/api/v1/employees', idiomaFichaAlta(['locale' => 'en']))
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonValidationErrors(['locale']);

    expect(DB::table('employees')->count())->toBe(0);
})->group('RF-KI-05', 'RF-GP-01');

it('acepta el ingles en cuanto la instalacion lo activa', function (): void {
    InstallationLocale::set('es', ['es', 'en']);
    $token = idiomaFichaToken();

    Api::as($token)->post('/api/v1/employees', idiomaFichaAlta(['locale' => 'en']))
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee.locale', 'en');
})->group('RF-KI-05', 'RF-GP-01');

it('acepta el idioma en mayusculas en el alta y en la modificacion, y lo guarda como la importacion', function (): void {
    // Un solo criterio de idioma activo: la importacion ya guardaba `EN` como
    // `en`; el alta y la modificacion respondian 422 al mismo valor.
    InstallationLocale::set('es', ['es', 'en']);
    $token = idiomaFichaToken();

    $uuid = Api::as($token)->post('/api/v1/employees', idiomaFichaAlta(['locale' => 'EN']))
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee.locale', 'en')
        ->json('employee.uuid');

    expect(DB::table('employees')->value('locale'))->toBe('en');

    Api::as($token)->patch('/api/v1/employees/'.(\is_string($uuid) ? $uuid : ''), ['locale' => 'Es'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('locale', 'es');

    expect(DB::table('employees')->value('locale'))->toBe('es');
})->group('RF-KI-05', 'RF-GP-01', 'RF-GP-03');

it('rechaza con 422 cambiar la ficha a un idioma inactivo, y la deja como estaba', function (): void {
    $token = idiomaFichaToken();
    $uuid = Api::as($token)->post('/api/v1/employees', idiomaFichaAlta())
        ->assertValidResponse(201)
        ->json('employee.uuid');

    Api::as($token)->patch('/api/v1/employees/'.(\is_string($uuid) ? $uuid : ''), ['locale' => 'en'])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonValidationErrors(['locale']);

    expect(DB::table('employees')->value('locale'))->toBe('es');
})->group('RF-KI-05', 'RF-GP-03');

// -----------------------------------------------------------------------------
// Importacion
// -----------------------------------------------------------------------------

it('importa la linea con un idioma inactivo, con su aviso y el idioma de la instalacion', function (): void {
    $token = idiomaFichaToken();

    idiomaFichaImportar($token, "nombre,apellidos,dni,fecha_alta,idioma\nMarta,Vidal,87654321X,2026-02-01,en\n")
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('summary.reject', 0)
        ->assertJsonPath('rows.0.outcome', 'create')
        ->assertJsonPath('rows.0.messages.0.code', 'locale_not_active')
        ->assertJsonPath('rows.0.messages.0.severity', 'warning')
        ->assertJsonPath('rows.0.messages.0.column', 'locale')
        ->assertJsonPath('rows.0.messages.0.detail', 'El idioma de esta línea no está activo en la instalación, así '
            .'que se ignora: un alta se crea con el idioma de la instalación y una ficha que ya existe conserva el '
            .'suyo. Si quieres ofrecer ese idioma, actívalo en Ajustes operativos > Idiomas y vuelve a importar.');

    expect(DB::table('employees')->count())->toBe(1)
        ->and(DB::table('employees')->value('locale'))->toBe('es');
})->group('RF-KI-05', 'RF-GP-05');

it('una ficha que ya existe conserva su idioma y la linea no cuenta como cambio', function (): void {
    $token = idiomaFichaToken();
    Api::as($token)->post('/api/v1/employees', idiomaFichaAlta())->assertValidResponse(201);

    idiomaFichaImportar($token, "nombre,apellidos,dni,fecha_alta,idioma\nMarta,Vidal,87654321X,2026-02-01,en\n")
        ->assertJsonPath('rows.0.outcome', 'unchanged')
        ->assertJsonPath('rows.0.changes', [])
        ->assertJsonPath('rows.0.messages.0.code', 'locale_not_active');

    expect(DB::table('employees')->value('locale'))->toBe('es');
})->group('RF-KI-05', 'RF-GP-05');

it('con el idioma activo no avisa, y lo guarda en la forma de la lista', function (): void {
    InstallationLocale::set('es', ['es', 'en']);
    $token = idiomaFichaToken();

    idiomaFichaImportar($token, "nombre,apellidos,dni,fecha_alta,idioma\nMarta,Vidal,87654321X,2026-02-01,EN\n")
        ->assertJsonPath('rows.0.outcome', 'create')
        ->assertJsonPath('rows.0.messages', []);

    expect(DB::table('employees')->value('locale'))->toBe('en');
})->group('RF-KI-05', 'RF-GP-05');

it('explica el aviso en ingles a quien pide ingles', function (): void {
    // Ingles activo para negociar, pero el fichero pide un idioma que no esta.
    InstallationLocale::set('es', ['es', 'en']);
    $token = idiomaFichaToken();

    idiomaFichaImportar($token, "nombre,apellidos,dni,fecha_alta,idioma\nMarta,Vidal,87654321X,2026-02-01,ro\n", 'en')
        ->assertJsonPath('rows.0.messages.0.code', 'locale_not_active')
        ->assertJsonPath('rows.0.messages.0.detail', 'The language in this row is not active on this installation, '
            .'so it is ignored: a new record gets the installation language and an existing one keeps its own. '
            .'To offer that language, turn it on in Operational settings > Languages and import again.');

    expect(DB::table('employees')->value('locale'))->toBe('es');
})->group('RF-KI-05', 'RF-GP-05');
