<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\DiagnosticsConfigurationAllowlist;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El codigo de servicio del quiosco no sale de la instalacion** (RF-KI-08,
 * RF-PD-09, ADR-020, regla dura 16; tarea 3.3, decision 6).
 *
 * El paquete de diagnostico lo genera el cliente y **se envia al fabricante**.
 * Que el codigo con el que se abre la pantalla de mantenimiento de todas sus
 * tablets viajara dentro seria entregarlo por correo, y ademas quedaria escrito
 * en un fichero que se guarda, se reenvia y se archiva.
 *
 * ## Por que hay tres comprobaciones y no una
 *
 * La lista de permitidos ya deja fuera `KIOSK_SERVICE_CODE` —de la familia
 * `KIOSK_` solo entran los techos de peticiones—, pero eso solo cubre la seccion
 * `env`. El paquete tiene diez secciones y una de ellas, `configuration`,
 * compara el `.env` con la base de datos: por ahi podria salir el valor si
 * alguien decidiera algun dia que la comparacion «es mas util con los dos
 * valores». La tercera es la de verdad: **el paquete entero, como texto**.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site('Hotel del diagnostico', 'Europe/Madrid');
    LicenseKeys::grantAll();
});

it('no deja pasar el codigo de servicio por la lista de permitidos del entorno', function (): void {
    // De la familia `KIOSK_` solo entran los techos de peticiones
    // (`KIOSK_*RATE*`). El codigo no es un umbral operativo: es un secreto.
    expect(DiagnosticsConfigurationAllowlist::allows('KIOSK_SERVICE_CODE'))->toBeFalse()
        // Y el control positivo, para que la comprobacion signifique algo: la
        // lista no esta simplemente devolviendo `false` a todo.
        ->and(DiagnosticsConfigurationAllowlist::allows('KIOSK_SCAN_RATE_PER_DEVICE'))->toBeTrue();
})->group('RF-PD-09', 'RF-KI-08', 'RS-08');

it('genera el paquete completo sin el codigo de servicio dentro', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['KIOSK_SERVICE_CODE' => '900112233']])
        ->assertStatus(200);

    $respuesta = Api::as($token)->post('/api/v1/diagnostics/bundle')->assertStatus(200);

    $contenido = $respuesta->getContent();

    expect($contenido)->toBeString();

    // EL PAQUETE ENTERO, COMO TEXTO. Es la comprobacion que no depende de por
    // que seccion podria colarse: si el codigo aparece en cualquier sitio —en
    // `env`, en la diferencia con el `.env`, en un hallazgo de `doctor` o en un
    // error captado—, esta prueba lo dice.
    expect((string) $contenido)->not->toContain('900112233')
        // Y el paquete sale COMPLETO, no recortado para esquivar el problema.
        ->and($respuesta->json('manifest.sections'))->toHaveCount(10);
})->group('RF-PD-09', 'RF-KI-08', 'RS-08');

it('avisa en la diferencia con el .env sin decir los dos valores', function (): void {
    // `SettingsDrift` devuelve SOLO la clave, nunca los valores: con ellos, la
    // seccion `configuration` seria una puerta trasera para sacar del `.env`
    // cualquier variable que ademas fuera una clave del catalogo, esquivando la
    // lista de permitidos.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $_ENV['KIOSK_SERVICE_CODE'] = '111222333';

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['KIOSK_SERVICE_CODE' => '900112233']])
        ->assertStatus(200);

    try {
        $respuesta = Api::as($token)->post('/api/v1/diagnostics/bundle')->assertStatus(200);

        $contenido = $respuesta->getContent();

        expect($contenido)->toBeString()
            ->and((string) $contenido)->not->toContain('900112233')
            ->and((string) $contenido)->not->toContain('111222333');
    } finally {
        unset($_ENV['KIOSK_SERVICE_CODE']);
    }
})->group('RF-PD-09', 'RF-KI-08', 'RF-PD-01');
