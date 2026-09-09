<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Identity\Application\Port\PortalAddressProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeInstructionsSheetRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/credentials/instructions-sheet` — la hoja que se entrega con la
 * tarjeta (tarea 5.11b, **RL-05**, RF-QR-06, RF-PD-08).
 *
 * SIN CHROMIUM. El puerto `InstructionsSheetRenderer` existe para esto: lo que
 * se comprueba aqui es el borde —ruta, ambito, policy, validacion del idioma,
 * cabeceras— y no la presencia de un binario en la maquina. Que la hoja quepa
 * en una cara, que lleve la direccion del portal impresa y que salga en los dos
 * idiomas lo comprueba `Tests\Integration\Identity\InstructionsSheetLayoutTest`
 * con el motor de verdad.
 *
 * CADA RESPUESTA PASA POR SPECTATOR: el cliente TypeScript de los tres
 * frontends se genera de `openapi.yaml`, asi que una desviacion aqui los rompe a
 * los tres a la vez y sin aviso (ADR-013).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * El doble del dibujante, ya enlazado, para poder afirmar **con que** se le
 * pidio dibujar.
 */
function fakeInstructionsSheet(): FakeInstructionsSheetRenderer
{
    $renderer = new FakeInstructionsSheetRenderer;

    app()->instance(InstructionsSheetRenderer::class, $renderer);

    return $renderer;
}

function credentialManagerToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

it('entrega la hoja en PDF, como adjunto y sin dejarla en ninguna cache', function (): void {
    $renderer = fakeInstructionsSheet();

    $response = Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet')
        ->assertValidRequest()
        ->assertValidResponse(200);

    $response->assertHeader('Content-Type', 'application/pdf');
    // El nombre lleva el idioma y ningun dato de persona (regla dura 21): acaba
    // en el historial de descargas de quien la imprime.
    $response->assertHeader('Content-Disposition', 'attachment; filename="hoja-empleado-es.pdf"');
    // `no-store` aunque no haya ningun secreto: la hoja lleva la marca y la
    // direccion del portal VIGENTES, y un proxy no debe servir las de ayer.
    $response->assertHeader('Cache-Control', 'no-store, private');

    expect((string) $response->baseResponse->getContent())->toBe(FakeInstructionsSheetRenderer::PDF_BYTES)
        ->and($renderer->renders())->toBe(1);
})->group('RL-05', 'RF-QR-06');

it('usa el idioma por defecto de la instalacion cuando no se pide ninguno', function (): void {
    // El idioma de la hoja es configuracion de la INSTALACION y no del navegador
    // que la descarga (regla dura 13): se pone `en` por defecto y se pide sin
    // `locale` para que lo que decida sea la fila, no una constante.
    InstallationLocale::set('en', ['en', 'es']);
    $renderer = fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet')
        ->assertValidResponse(200)
        ->assertHeader('Content-Disposition', 'attachment; filename="hoja-empleado-en.pdf"');

    expect($renderer->lastLocale())->toBe('en');
})->group('RL-05', 'RF-PD-01');

it('sale en cualquiera de los idiomas activos si se pide', function (): void {
    InstallationLocale::set('es', ['es', 'en']);
    $renderer = fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet', ['locale' => 'en'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertHeader('Content-Disposition', 'attachment; filename="hoja-empleado-en.pdf"');

    expect($renderer->lastLocale())->toBe('en');
})->group('RL-05', 'RF-PD-01');

it('rechaza un idioma que la instalacion no tiene activo', function (): void {
    // `fr` esta bien formado y no es uno de los idiomas de esta instalacion. La
    // alternativa —imprimir la hoja con las frases sin traducir— seria peor que
    // el error: se imprime, se entrega y nadie la lee entera.
    InstallationLocale::set('es', ['es', 'en']);
    $renderer = fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet', ['locale' => 'fr'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');

    expect($renderer->renders())->toBe(0);
})->group('RL-05', 'RF-PD-01');

it('rechaza un idioma que no tiene la forma del contrato', function (string $locale): void {
    // El patron del contrato es `^[a-z]{2}$`. Sin esta comprobacion, cualquier
    // cadena llegaria a compararse con la lista de idiomas activos, y el dia que
    // esa lista se construyera de otra forma tendriamos una entrada libre.
    $renderer = fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet', ['locale' => $locale])
        ->assertValidResponse(422);

    expect($renderer->renders())->toBe(0);
})->with([
    'en mayusculas' => 'ES',
    'el nombre del idioma' => 'espanol',
    'con region' => 'es-ES',
    'vacio' => '',
])->group('RL-05');

it('rechaza los parametros que el endpoint no conoce', function (): void {
    // La hoja no tiene formato, ni titular, ni nada que elegir mas alla del
    // idioma: quien envie otra cosa se iria convencido de haber cambiado algo.
    fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet', ['format' => 'a5'])
        ->assertValidResponse(422);
})->group('RL-05');

it('imprime la direccion del portal de ESTA instalacion, con su barra final', function (): void {
    // Es lo que ningun PDF estatico del paquete de documentacion puede saber, y
    // la razon por la que este endpoint existe (ficha 5.11b, decision 1). El
    // `/portal/` es donde Nginx sirve esa SPA: sin la barra final, quien teclee
    // la direccion recibe un 404.
    Config::set('app.url', 'https://hotel-marina.example');
    app()->forgetInstance(PortalAddressProvider::class);

    $renderer = fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet')
        ->assertValidResponse(200);

    expect($renderer->lastPortalUrl())->toBe('https://hotel-marina.example/portal/');
})->group('RL-05');

it('lleva la marca de la instalacion y no una escrita en el codigo', function (): void {
    // RF-PD-08 y regla dura 13. Sin nada configurado, la marca es la del
    // PRODUCTO —nunca la de otro cliente— y llega por el puerto, no por una
    // constante de la hoja.
    $renderer = fakeInstructionsSheet();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet')
        ->assertValidResponse(200);

    expect($renderer->brands)->toHaveCount(1)
        ->and($renderer->brands[0]->applicationName)->toBe('KronoQR')
        ->and($renderer->brands[0]->accentColor)->toStartWith('#');
})->group('RL-05', 'RF-PD-08');

it('no la sirve a una sesion del portal del empleado', function (): void {
    // RF-ID-07: el empleado entra al portal a ver LO SUYO, con ambito
    // `self:read`. La hoja es un documento de gestion —se imprime para
    // entregarla— y su ambito es `credentials:*`; que no lleve ningun dato de
    // nadie no la convierte en publica.
    //
    // Las demas parejas rol x endpoint —quiosco, auditor, responsable de
    // departamento, cuenta con rol de empleado, sesion pendiente de 2FA y sin
    // token— estan en `Tests\Feature\AuthorizationNegativeTest`, que es la matriz
    // de la regla dura 18. Esta no cabe alli: necesita un empleado con PIN.
    fakeInstructionsSheet();

    $employee = WorkforceFixtures::employee(WorkforceFixtures::site());

    Api::as(PortalLogins::open($employee))
        ->get('/api/v1/credentials/instructions-sheet')
        ->assertStatus(403);
})->group('RL-05', 'RF-ID-07', 'RQ-07');

it('no escribe nada: ni asiento de auditoria ni fila ninguna', function (): void {
    // Es un `GET` y lo es de verdad. La hoja es el MISMO documento para toda la
    // plantilla, no lleva ningun dato de persona y no acuña ningun QR: no hay
    // acto con relevancia legal que registrar (al reves que `print`, `deliver` o
    // `revoke`, que si escriben en `audit_log`).
    fakeInstructionsSheet();

    $antes = DB::table('audit_log')->count();

    Api::as(credentialManagerToken())
        ->get('/api/v1/credentials/instructions-sheet')
        ->assertValidResponse(200);

    expect(DB::table('audit_log')->count())->toBe($antes);
})->group('RL-05', 'RQ-07');
