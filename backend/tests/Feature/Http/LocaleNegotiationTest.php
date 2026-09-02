<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\App;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El idioma de la respuesta se negocia con `Accept-Language` (regla dura 13,
 * RF-PD-01), acotado a los idiomas que la instalacion tiene activos.
 *
 * Antes no habia negociacion: todo salia en `APP_LOCALE`, y un panel en
 * castellano recibia los mensajes de validacion en ingles («The include open
 * shifts field must be true or false»). Lo que estas pruebas fijan:
 *
 *   - Lo que pide el navegador manda, si la instalacion tiene ese idioma.
 *   - Sin cabecera, o con un idioma que no esta en la lista, se responde en el
 *     de la instalacion — lo que pasaba antes, para que nadie pierda nada.
 *   - La lista la pone la configuracion, no el hecho de que exista `lang/xx`.
 *   - Los DOCUMENTOS no se negocian: un CSV sale en el idioma de la instalacion
 *     aunque el navegador pida otro (`UseInstallationLocale`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {

    // El informe por periodo y la presencia en tiempo real son funcionalidad
    // ACCESORIA (ADR-023, tarea 5.3): sin una licencia que las conceda, el
    // primero responde `402` con el aviso de licencia y la segunda degrada a
    // sondeo. Aqui se prueba la funcionalidad; su degradacion tiene fichero
    // propio, `tests/Feature/Product/LicenseDegradesAccessoriesTest.php`.
    //
    // **Nada del registro legal necesita esta llamada**: el fichaje, la consulta
    // de jornadas, el portal y la exportacion para la Inspeccion funcionan sin
    // licencia por diseño, y que sus pruebas no la hagan es la comprobacion
    // silenciosa de eso (regla dura 15).
    LicenseKeys::grantAll();

    Spectator::using('openapi.yaml');

    // La instalacion habla castellano, como en `.env.example`.
    App::setLocale('es');
});

/**
 * Un centro, una persona con una jornada y una sesion de RRHH, para las rutas
 * que necesitan datos.
 *
 * @return array{token: string}
 */
function contextoDeIdioma(): array
{
    $site = WorkforceFixtures::site('Hotel de idiomas', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site);

    PeriodReportFixtures::workDay($site, $employee, '2026-03-02', '2026-03-02 06:00', '2026-03-02 14:00');

    return ['token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH))];
}

/**
 * El cuerpo de una respuesta en streaming (mismo apaño que en las pruebas de
 * exportacion, con otro nombre porque las funciones de Pest son globales).
 *
 * @param  TestResponse<Response>  $response
 */
function cuerpoDelDocumento(TestResponse $response): string
{
    $base = $response->baseResponse;

    if (! $base instanceof StreamedResponse) {
        return (string) $base->getContent();
    }

    ob_start();
    $base->sendContent();

    return (string) ob_get_clean();
}

it('responde en el idioma que pide el navegador, si la instalacion lo tiene', function (string $header, string $expected): void {
    Api::guest()
        ->withHeaders(['Accept-Language' => $header])
        ->post('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', $expected);
})->with([
    'ingles a secas' => ['en', 'The email address field is required.'],
    // Lo que manda Chrome en un Windows en castellano: con region y con q.
    'castellano con region' => ['es-ES,es;q=0.9,en;q=0.8', 'El campo correo electrónico es obligatorio.'],
    'ingles preferido sobre castellano' => ['en-GB,en;q=0.9,es;q=0.5', 'The email address field is required.'],
])->group('RF-PD-01');

it('sin cabecera, o con un idioma que la instalacion no tiene, responde en el de la instalacion', function (array $headers): void {
    Api::guest()
        ->withHeaders($headers)
        ->post('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'El campo correo electrónico es obligatorio.');
})->with([
    'sin cabecera' => [[]],
    'frances' => [['Accept-Language' => 'fr-FR,fr;q=0.9']],
    'cabecera rota' => [['Accept-Language' => ';;;']],
])->group('RF-PD-01');

it('solo negocia entre los idiomas que la instalacion tiene activos, aunque exista la traduccion', function (): void {
    // Regla dura 13: que `lang/en` exista en el paquete no significa que esta
    // instalacion ofrezca ingles. La lista la pone la configuracion.
    config()->set('app.supported_locales', ['es']);

    Api::guest()
        ->withHeaders(['Accept-Language' => 'en'])
        ->post('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'El campo correo electrónico es obligatorio.');
})->group('RF-PD-01');

it('traduce tambien el nombre del campo, no solo la frase', function (): void {
    // Sin `validation.attributes`, el mensaje diria «El campo include open
    // shifts…», que no es castellano ni es lo que pone en la casilla.
    $contexto = contextoDeIdioma();

    Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-07', 'include_open_shifts' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonPath('errors.include_open_shifts.0', 'El campo contar los días con turno abierto debe ser verdadero o falso.');
})->group('RF-PD-01', 'RF-IN-01');

it('los criterios del informe en pantalla salen en el idioma negociado', function (): void {
    // `meta.criteria` es texto para una persona: sigue al panel, no a la
    // instalacion. El fichero es otra historia (prueba siguiente).
    $contexto = contextoDeIdioma();

    $respuesta = Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-07', 'granularity' => 'range'])
        ->assertValidResponse(200);

    /** @var list<string> $criterios */
    $criterios = $respuesta->json('meta.criteria');

    expect(implode(' ', $criterios))->toContain('not split at midnight')
        ->and(implode(' ', $criterios))->not->toContain('no se parte a medianoche');
})->group('RF-PD-01', 'RF-IN-01');

it('un documento sale en el idioma de la instalacion aunque el navegador pida otro', function (): void {
    // El idioma que importa es el del programa que abrira el fichero, no el del
    // navegador que lo descargo (`CsvDialect::delimiterFor()`): cabecera en
    // ingles, CSV en castellano y con `;`.
    $contexto = contextoDeIdioma();

    $respuesta = Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->get('/api/v1/reports/period/export', ['format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-07', 'granularity' => 'range'])
        ->assertOk();

    $cuerpo = cuerpoDelDocumento($respuesta);

    expect($cuerpo)->toContain('Trabajado;Contratado')
        ->and($cuerpo)->toContain('no se parte a medianoche')
        ->and($cuerpo)->not->toContain('Worked,Contracted');
})->group('RF-PD-01', 'RF-IN-04');
