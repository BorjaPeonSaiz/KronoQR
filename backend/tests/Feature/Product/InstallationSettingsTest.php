<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * `GET` y `PATCH /api/v1/settings` — la configuracion de la instalacion
 * (RF-PD-01, ADR-017, regla dura 13).
 *
 * Es la infraestructura que hace cumplible «nada especifico de un cliente vive
 * en el codigo»: marca, idiomas y umbrales operativos son datos editables desde
 * el panel. Lo que estas pruebas fijan es que sean editables **de verdad** y que
 * una instalacion sin configurar arranque igual.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013) y una respuesta que no lo cumple rompe el
 * cliente TypeScript generado de el.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function adminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('devuelve el catalogo entero con los valores de serie cuando no hay ninguna fila', function (): void {
    // El resultado esperado literal de la tarea 5.1: una instalacion **sin
    // ninguna fila** en `installation_settings` arranca y responde con los
    // valores por defecto. Se borran las cuatro que siembra la migracion de la
    // 1.3 justo para poder comprobarlo.
    DB::table('installation_settings')->delete();

    $response = Api::as(adminToken())->get('/api/v1/settings')
        ->assertValidRequest()
        ->assertValidResponse(200);

    $keys = $response->json('data.*.key');
    $sources = $response->json('data.*.source');

    expect($keys)->toContain('ATTENDANCE_MAX_SHIFT_HOURS', 'BRANDING_APP_NAME', 'LOCALE_AVAILABLE')
        ->and($sources)->each->toBe('product_default')
        ->and($response->json('meta.unknown_keys'))->toBe([]);
})->group('RF-PD-01');

it('distingue el valor configurado del valor de serie', function (): void {
    $token = adminToken();

    Api::as($token)->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 10]])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $response = Api::as($token)->get('/api/v1/settings')->assertValidResponse(200);

    $byKey = settingsByKey($response->json('data'));

    expect($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['value'])->toBe(10)
        ->and($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['source'])->toBe('installation')
        // La marca no se ha tocado y sigue siendo la del producto: el valor por
        // defecto ES el producto, nunca la marca de otro cliente.
        ->and($byKey['BRANDING_APP_NAME']['value'])->toBe('KronoQR')
        ->and($byKey['BRANDING_APP_NAME']['source'])->toBe('product_default');
})->group('RF-PD-01');

it('publica el tipo, el impacto y las restricciones de cada clave', function (): void {
    // El panel dibuja el control con esto y no con una copia del catalogo en
    // TypeScript, que es como se acaba con un maximo distinto en cada lado.
    $response = Api::as(adminToken())->get('/api/v1/settings')->assertValidResponse(200);

    $byKey = settingsByKey($response->json('data'));

    expect($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['type'])->toBe('integer')
        ->and($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['impact'])->toBe('compliance_review')
        ->and($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['affects_worked_hours'])->toBeFalse()
        ->and($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['constraints'])->toBe(['minimum' => 1, 'maximum' => 24])
        // La unica clave del catalogo que hoy mueve minutos (RF-AT-06).
        ->and($byKey['ATTENDANCE_DEBOUNCE_SECONDS']['impact'])->toBe('worked_hours')
        ->and($byKey['ATTENDANCE_DEBOUNCE_SECONDS']['affects_worked_hours'])->toBeTrue()
        ->and($byKey['LOCALE_AVAILABLE']['type'])->toBe('text_list')
        ->and($byKey['LOCALE_AVAILABLE']['constraints'])->toBe(['allowed' => ['es', 'en']]);
})->group('RF-PD-01');

it('enseña las filas que este binario no reconoce sin dejar de funcionar', function (): void {
    // Solo pueden venir de una version posterior o de una edicion a mano. Que
    // rompieran dejaria a un centro sin fichar por una fila sobrante; que se
    // callaran esconderia una actualizacion a medias.
    DB::table('installation_settings')->insert([
        'key' => 'ATTENDANCE_LEGACY_TOLERANCE',
        'value' => '5',
        'updated_at' => '2026-01-01 00:00:00+00',
    ]);

    Api::as(adminToken())->get('/api/v1/settings')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.unknown_keys', ['ATTENDANCE_LEGACY_TOLERANCE']);
})->group('RF-PD-01');

it('cambia varias claves a la vez y devuelve el conjunto completo', function (): void {
    $response = Api::as(adminToken())
        ->patch('/api/v1/settings', [
            'settings' => [
                'ATTENDANCE_MAX_SHIFT_HOURS' => 10,
                'BRANDING_APP_NAME' => 'Hotel Marina',
            ],
        ])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $byKey = settingsByKey($response->json('data'));

    expect($byKey)->toHaveCount(9)
        ->and($byKey['ATTENDANCE_MAX_SHIFT_HOURS']['value'])->toBe(10)
        ->and($byKey['BRANDING_APP_NAME']['value'])->toBe('Hotel Marina');

    expect(DB::table('installation_settings')->where('key', 'BRANDING_APP_NAME')->value('value'))
        ->toBe('"Hotel Marina"');
})->group('RF-PD-01');

it('guarda quien hizo el cambio', function (): void {
    // RN-13 por extension: un parametro del calculo cambiado sin autor no
    // explica nada seis meses despues.
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($user))
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_DEBOUNCE_SECONDS' => 90]])
        ->assertValidResponse(200);

    expect(DB::table('installation_settings')->where('key', 'ATTENDANCE_DEBOUNCE_SECONDS')->value('updated_by_user_id'))
        ->toBe($user->id);
})->group('RF-PD-01');

it('acepta una lista de idiomas y su idioma por defecto en la misma peticion', function (): void {
    $response = Api::as(adminToken())
        ->patch('/api/v1/settings', [
            'settings' => [
                'LOCALE_DEFAULT' => 'en',
                'LOCALE_AVAILABLE' => ['en'],
            ],
        ])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $byKey = settingsByKey($response->json('data'));

    expect($byKey['LOCALE_DEFAULT']['value'])->toBe('en')
        ->and($byKey['LOCALE_AVAILABLE']['value'])->toBe(['en']);
})->group('RF-PD-01');

it('rechaza dejar el idioma por defecto fuera de los disponibles', function (): void {
    // Invariante ENTRE claves: ninguna definicion la puede comprobar sola, y el
    // conjunto se valida antes de tocar la base de datos para que el orden de
    // escritura no pueda dejar la instalacion en un estado imposible.
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['LOCALE_AVAILABLE' => ['en']]])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');

    expect(DB::table('installation_settings')->where('key', 'LOCALE_AVAILABLE')->exists())->toBeFalse();
})->group('RF-PD-01');

it('rechaza una clave que el catalogo no declara, y dice cual', function (): void {
    // Aceptarla produciria una fila que nadie lee: el cliente creeria haber
    // configurado algo y el sistema seguiria aplicando el valor de serie.
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAGIC_NUMBER' => 3]])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['settings.ATTENDANCE_MAGIC_NUMBER']]);
})->group('RF-PD-01');

it('rechaza un valor fuera del rango de su clave', function (): void {
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 40]])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['settings.ATTENDANCE_MAX_SHIFT_HOURS']]);
})->group('RF-PD-01');

it('rechaza un valor del tipo equivocado', function (mixed $value): void {
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => $value]])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['settings.ATTENDANCE_MAX_SHIFT_HOURS']]);
})->with([
    'una cadena' => 'doce',
    // «12» viene de un JSON escrito a mano y aceptarlo convertiria un error de
    // configuracion en un umbral silenciosamente distinto del que se cree haber
    // puesto.
    'un numero entrecomillado' => '12',
    'una lista' => [[12]],
])->group('RF-PD-01');

it('rechaza un color de acento mal escrito', function (): void {
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_ACCENT_COLOR' => 'azul']])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['settings.BRANDING_ACCENT_COLOR']]);
})->group('RF-PD-01');

it('rechaza un idioma que el producto no trae traducido', function (): void {
    // El conjunto es cerrado: ofrecer un idioma sin traduccion daria una
    // interfaz a medias.
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['LOCALE_AVAILABLE' => ['es', 'fr']]])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['settings.LOCALE_AVAILABLE.1']]);
})->group('RF-PD-01');

it('rechaza un campo suelto junto a settings', function (): void {
    // `RejectsUnknownInput`: quien lo envia cree haber fijado algo.
    Api::as(adminToken())
        ->patch('/api/v1/settings', [
            'settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 10],
            'updated_by_user_id' => 1,
        ])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['updated_by_user_id']]);
})->group('RF-PD-01');

it('rechaza un cuerpo sin ninguna clave', function (): void {
    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => []])
        ->assertValidResponse(422);
})->group('RF-PD-01');

it('devuelve los errores de validacion en el idioma negociado', function (): void {
    // `NegotiateLocale` acota `Accept-Language` a los idiomas activos. Sin esto,
    // el panel enseñaria el mensaje en ingles a un administrador que trabaja en
    // castellano.
    $token = adminToken();

    $spanish = firstErrorFor(
        Api::as($token)->withHeaders(['Accept-Language' => 'es'])
            ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 40]])
            ->assertStatus(422)
            ->json('errors'),
    );

    $english = firstErrorFor(
        Api::as($token)->withHeaders(['Accept-Language' => 'en'])
            ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 40]])
            ->assertStatus(422)
            ->json('errors'),
    );

    expect($spanish)->not->toBe($english)
        // El nombre legible de la clave, no el de la columna.
        ->and($spanish)->toContain('anómalo')
        ->and($english)->toContain('anomalous');
})->group('RF-PD-01');

it('aplica el umbral nuevo sin reiniciar nada', function (): void {
    // La prueba que de verdad cierra RF-PD-01: cambiar el valor desde el panel
    // tiene que cambiar lo que el nucleo aplica, y sin desplegar nada. Si la
    // cache no se invalidara, esto seguiria devolviendo 12.
    $provider = app(OperationalSettingsProvider::class);

    expect($provider->forSite(1)->anomalousShiftMinutes)->toBe(12 * 60);

    Api::as(adminToken())
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 9]])
        ->assertValidResponse(200);

    // Instancia nueva: el proveedor memoiza por peticion a proposito, y en
    // produccion cada peticion arranca la suya.
    app()->forgetInstance(OperationalSettingsProvider::class);

    expect(app(OperationalSettingsProvider::class)->forSite(1)->anomalousShiftMinutes)
        ->toBe(9 * 60);
})->group('RF-PD-01', 'RN-08');

it('sirve la marca del producto mientras nadie la configure, y la del cliente en cuanto la configure', function (): void {
    // **Sin etiqueta RF-PD-08 a proposito**: ese requisito pide la marca aplicada
    // a las tres aplicaciones y a los PDF, y eso lo cumple la tarea 5.8. Lo que
    // esto comprueba es que el PUERTO por el que llegara ya resuelve desde la
    // configuracion, que es RF-PD-01.
    $branding = app(BrandingProvider::class);

    expect($branding->current()->applicationName)->toBe('KronoQR')
        ->and($branding->current()->logoPath)->toBeNull()
        ->and($branding->current()->accentColor)->toBe('#111827');

    Api::as(adminToken())
        ->patch('/api/v1/settings', [
            'settings' => [
                'BRANDING_APP_NAME' => 'Hotel Marina',
                'BRANDING_ACCENT_COLOR' => '#0f172a',
                'BRANDING_LOGO_PATH' => '/srv/kronoqr/marca/logo.png',
            ],
        ])
        ->assertValidResponse(200);

    app()->forgetInstance(BrandingProvider::class);

    $updated = app(BrandingProvider::class)->current();

    expect($updated->applicationName)->toBe('Hotel Marina')
        ->and($updated->accentColor)->toBe('#0f172a')
        ->and($updated->logoPath)->toBe('/srv/kronoqr/marca/logo.png');
})->group('RF-PD-01');

/**
 * Las filas de `data` indexadas por su clave.
 *
 * @return array<string, array<string, mixed>>
 */
function settingsByKey(mixed $data): array
{
    expect($data)->toBeArray();

    $byKey = [];

    /** @var array<int, array<string, mixed>> $rows */
    $rows = is_array($data) ? $data : [];

    foreach ($rows as $row) {
        $key = $row['key'] ?? null;

        if (is_string($key)) {
            $byKey[$key] = $row;
        }
    }

    return $byKey;
}
/**
 * El primer mensaje de error de `ATTENDANCE_MAX_SHIFT_HOURS`.
 *
 * Se indexa a mano y no con `json('errors.settings\.CLAVE.0')` porque el nombre
 * del campo lleva un punto —`settings.ATTENDANCE_MAX_SHIFT_HOURS`— y la notacion
 * de puntos de `data_get` lo interpreta como dos niveles.
 */
function firstErrorFor(mixed $errors): string
{
    expect($errors)->toBeArray();

    /** @var array<string, mixed> $byField */
    $byField = is_array($errors) ? $errors : [];
    $messages = $byField['settings.ATTENDANCE_MAX_SHIFT_HOURS'] ?? null;

    expect($messages)->toBeArray();

    $first = is_array($messages) ? ($messages[0] ?? null) : null;

    expect($first)->toBeString();

    return is_string($first) ? $first : '';
}

it('traduce tambien los errores que produce el dominio', function (): void {
    // La invariante ENTRE claves no la puede comprobar el `FormRequest` —depende
    // de lo que ya hay guardado—, asi que la lanza el dominio y la traduce el
    // borde. Antes el `render()` volcaba el mensaje de la excepcion tal cual, lo
    // que metia literales castellanos dentro de `Domain/` y le respondia en
    // castellano a un panel puesto en ingles.
    $token = adminToken();

    $spanish = Api::as($token)->withHeaders(['Accept-Language' => 'es'])
        ->patch('/api/v1/settings', ['settings' => ['LOCALE_AVAILABLE' => ['en']]])
        ->assertStatus(422)
        ->json('errors.settings.0');

    $english = Api::as($token)->withHeaders(['Accept-Language' => 'en'])
        ->patch('/api/v1/settings', ['settings' => ['LOCALE_AVAILABLE' => ['en']]])
        ->assertStatus(422)
        ->json('errors.settings.0');

    expect($spanish)->toBeString()
        ->and($english)->toBeString()
        ->and($spanish)->toContain('idioma por defecto')
        ->and($english)->toContain('default language')
        // Y ninguno de los dos es la clave de traduccion suelta, que es el fallo
        // silencioso de este mecanismo.
        ->and($spanish)->not->toContain('settings.errors');
})->group('RF-PD-01');

it('traduce el motivo de una fila descartada al idioma negociado', function (): void {
    // El mismo mecanismo por el otro camino: `meta.invalid_keys` lo lee una
    // persona, asi que su motivo tambien se traduce.
    DB::table('installation_settings')->updateOrInsert(
        ['key' => 'BRANDING_ACCENT_COLOR'],
        ['value' => '"azul"', 'updated_at' => '2026-01-01 00:00:00+00'],
    );

    $token = adminToken();

    $spanish = Api::as($token)->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/settings')->assertStatus(200)->json('meta.invalid_keys.0.reason');

    $english = Api::as($token)->withHeaders(['Accept-Language' => 'en'])
        ->get('/api/v1/settings')->assertStatus(200)->json('meta.invalid_keys.0.reason');

    expect($spanish)->toBeString()
        ->and($english)->toBeString()
        ->and($spanish)->not->toBe($english)
        ->and($spanish)->not->toContain('settings.errors');
})->group('RF-PD-01');
