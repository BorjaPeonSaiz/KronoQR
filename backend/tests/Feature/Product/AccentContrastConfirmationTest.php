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

/*
 * `PATCH /api/v1/settings` con un acento sin contraste sobre las superficies claras (MB2,
 * RF-PD-08).
 *
 * Lo que encontro la verificacion 2.1.0: el backend solo validaba la FORMA del
 * color, y un `PATCH` directo guardaba un acento ilegible sin ningun aviso. La
 * decision de la 2.2.0 es confirmacion explicita y no rechazo duro (doc 06 §7,
 * «contraste avisado, no impuesto»): sin `confirm_low_contrast: true`, `422`
 * con `type` propio y el error en `settings.BRANDING_ACCENT_COLOR`; con ella, se
 * guarda y queda en `audit_log` quien lo hizo.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

const ACCENT_CONTRAST_TYPE = 'urn:kronoqr:problem:low-contrast-accent';

function accentContrastAdminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

function accentContrastStoredAccent(): ?string
{
    $value = DB::table('installation_settings')->where('key', 'BRANDING_ACCENT_COLOR')->value('value');

    if (! is_string($value)) {
        return null;
    }

    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

    return is_string($decoded) ? $decoded : null;
}

/**
 * El primer mensaje colgado del campo del acento, o cadena vacia.
 *
 * @param  TestResponse<Response>  $response
 */
function accentContrastFieldError(TestResponse $response): string
{
    $errors = $response->json('errors');
    $messages = is_array($errors) ? ($errors['settings.BRANDING_ACCENT_COLOR'] ?? null) : null;
    $first = is_array($messages) ? ($messages[0] ?? null) : null;

    return is_string($first) ? $first : '';
}

it('rechaza con 422 y tipo propio un acento que no llega a 4,5:1 sobre las superficies claras y no viene confirmado', function (string $color): void {
    Api::as(accentContrastAdminToken())
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_ACCENT_COLOR' => $color]])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ACCENT_CONTRAST_TYPE)
        ->assertJsonStructure(['errors' => ['settings.BRANDING_ACCENT_COLOR']]);

    expect(accentContrastStoredAccent())->toBeNull();
})->with([
    'amarillo palido (1,22:1)' => ['#ffe14d'],
    'casi blanco' => ['#fafafa'],
    'gris medio (3,72:1)' => ['#808080'],
    'rojo puro (3,77:1)' => ['#ff0000'],
])->group('RF-PD-08', 'RF-PD-01');

it('trata confirm_low_contrast false igual que si no viniera', function (): void {
    Api::as(accentContrastAdminToken())
        ->patch('/api/v1/settings', [
            'settings' => ['BRANDING_ACCENT_COLOR' => '#ffe14d'],
            'confirm_low_contrast' => false,
        ])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', ACCENT_CONTRAST_TYPE);
})->group('RF-PD-08');

it('no guarda ninguna clave de la peticion cuando el acento no viene confirmado', function (): void {
    // Todo o nada: un nombre guardado y un color rechazado dejarian la marca a
    // medias, y el panel no sabria que repetir.
    Api::as(accentContrastAdminToken())
        ->patch('/api/v1/settings', ['settings' => [
            'BRANDING_APP_NAME' => 'Hotel Marina',
            'BRANDING_ACCENT_COLOR' => '#ffe14d',
        ]])
        ->assertValidResponse(422);

    expect(DB::table('installation_settings')->whereIn('key', ['BRANDING_APP_NAME', 'BRANDING_ACCENT_COLOR'])->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'calculation_setting.changed')->count())->toBe(0);
})->group('RF-PD-08', 'RL-04');

it('guarda el acento cuando viene confirmado, y deja el asiento de quien lo confirmo', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $response = Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/settings', [
            'settings' => ['BRANDING_ACCENT_COLOR' => '#ffe14d'],
            'confirm_low_contrast' => true,
        ])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $accent = null;

    foreach ((array) $response->json('data') as $setting) {
        if (is_array($setting) && ($setting['key'] ?? null) === 'BRANDING_ACCENT_COLOR') {
            $accent = $setting['value'] ?? null;
        }
    }

    expect($accent)->toBe('#ffe14d')
        ->and(accentContrastStoredAccent())->toBe('#ffe14d');

    $entry = DB::table('audit_log')->where('action', 'calculation_setting.changed')->orderByDesc('id')->first();
    expect($entry)->not->toBeNull();
    $payload = (array) json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['key'] ?? null)->toBe('BRANDING_ACCENT_COLOR')
        ->and($entry?->actor_id)->toBe($admin->id);
})->group('RF-PD-08', 'RL-04');

it('no pide confirmacion a un acento que llega', function (string $color): void {
    Api::as(accentContrastAdminToken())
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_ACCENT_COLOR' => $color]])
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect(accentContrastStoredAccent())->toBe($color);
})->with([
    'justo por encima del minimo (4,53:1)' => ['#727272'],
    'un azul oscuro' => ['#0f5c8c'],
])->group('RF-PD-08');

it('no vuelve a pedir confirmacion de un acento ya guardado al cambiar otra clave', function (): void {
    // El panel manda las tres claves de marca al guardar. Si el color ya
    // aceptado volviera a exigir confirmacion, cambiar el nombre del hotel
    // obligaria a confirmar otra vez un color que nadie ha tocado.
    $token = accentContrastAdminToken();

    Api::as($token)->patch('/api/v1/settings', [
        'settings' => ['BRANDING_ACCENT_COLOR' => '#ffe14d'],
        'confirm_low_contrast' => true,
    ])->assertValidResponse(200);

    Api::as($token)->patch('/api/v1/settings', ['settings' => [
        'BRANDING_APP_NAME' => 'Hotel Marina',
        'BRANDING_ACCENT_COLOR' => '#ffe14d',
    ]])
        ->assertValidRequest()
        ->assertValidResponse(200);
})->group('RF-PD-08');

it('exige un booleano de verdad en la confirmacion', function (mixed $confirmacion): void {
    // Un «1» o un «true» entre comillas escritos a mano no son alguien diciendo
    // «lo he visto». 422 de validacion, colgado del campo, antes de mirar el
    // color.
    Api::as(accentContrastAdminToken())
        ->patch('/api/v1/settings', [
            'settings' => ['BRANDING_ACCENT_COLOR' => '#ffe14d'],
            'confirm_low_contrast' => $confirmacion,
        ])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => ['confirm_low_contrast']]);

    expect(accentContrastStoredAccent())->toBeNull();
})->with([
    'una cadena' => ['true'],
    'un entero' => [1],
    'nulo' => [null],
])->group('RF-PD-08');

it('explica el contraste en el idioma negociado, con las cifras en su formato', function (): void {
    $token = accentContrastAdminToken();
    $body = ['settings' => ['BRANDING_ACCENT_COLOR' => '#ffe14d']];

    $spanish = accentContrastFieldError(Api::as($token)->withHeaders(['Accept-Language' => 'es'])
        ->patch('/api/v1/settings', $body)
        ->assertStatus(422));

    $english = accentContrastFieldError(Api::as($token)->withHeaders(['Accept-Language' => 'en'])
        ->patch('/api/v1/settings', $body)
        ->assertStatus(422));

    expect($spanish)->toBe('El color #ffe14d contrasta 1,22:1 sobre el fondo claro del panel y del portal, y el texto necesita al menos 4,5:1. '
        .'Las pantallas y los PDF lo oscurecerán hasta que se lea bien. Para guardarlo así, confírmalo explícitamente.')
        ->and($english)->toBe('The colour #ffe14d reaches 1.22:1 on the light background of the panel and the portal, and text needs at least 4.5:1. '
            .'Screens and PDF documents will darken it until it reads well. To save it anyway, confirm it explicitly.');
})->group('RF-PD-08');

it('trunca la cifra en vez de redondearla, para no decir 4,5 de un color que no llega a 4,5', function (): void {
    // `#737373` da 4,466:1 sobre el fondo de pagina: redondeado diria «4,47» y
    // truncado dice «4,46». Con un color de 4,499 el redondeo diria «4,5 … y el
    // minimo es 4,5», que no lo entenderia nadie.
    $mensaje = accentContrastFieldError(Api::as(accentContrastAdminToken())->withHeaders(['Accept-Language' => 'es'])
        ->patch('/api/v1/settings', ['settings' => ['BRANDING_ACCENT_COLOR' => '#737373']])
        ->assertStatus(422));

    expect($mensaje)->toContain('contrasta 4,46:1');
})->group('RF-PD-08');
