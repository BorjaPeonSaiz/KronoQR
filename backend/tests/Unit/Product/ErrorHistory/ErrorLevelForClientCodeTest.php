<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\ClientErrorCode;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/*
 * `critical` es lo que impide fichar, **y solo un quiosco puede producirlo**
 * (RF-PD-15, decision 3, revision de seguridad).
 *
 * ## Por que el origen entra en la decision
 *
 * Lo decide el servidor a partir del codigo, nunca el cliente. Pero con el
 * codigo a solas, una sesion de portal podia enviar `kiosk.camera.unavailable` y
 * fabricar una fila `critical` que dispara la alerta al IT del cliente de
 * madrugada, por una camara que ningun quiosco ha tenido. Ahora el origen entra
 * en la firma, y `critical` solo sale con origen `kiosk`.
 *
 * Son dos controles y basta con uno: `ClientErrorCode::isKnown()` rechaza el
 * codigo antes, en la validacion; esto es la red de debajo.
 */

it('marca como critico todo codigo de quiosco que impide fichar', function (string $codigo): void {
    expect(ErrorLevel::forClientCode(ErrorSource::Kiosk, $codigo))->toBe(ErrorLevel::Critical);
})->with(ErrorLevel::criticalClientCodes())->group('RF-PD-15');

it('deja en error cualquier otro codigo de quiosco', function (string $codigo): void {
    expect(ErrorLevel::forClientCode(ErrorSource::Kiosk, $codigo))->toBe(ErrorLevel::Error);
})->with([
    'kiosk.heartbeat.failed',
    'kiosk.sync.retry_exhausted',
    'codigo.que.no.existe',
])->group('RF-PD-15');

it('ningun origen que no sea el quiosco puede producir un critico', function (ErrorSource $source): void {
    // Ni aunque llegue un codigo de la lista de criticos: el panel y el portal
    // no deciden que es critico en la instalacion de un hotel.
    foreach (ErrorLevel::criticalClientCodes() as $codigo) {
        expect(ErrorLevel::forClientCode($source, $codigo))->toBe(ErrorLevel::Error);
    }
})->with([
    'panel' => [ErrorSource::Admin],
    'portal' => [ErrorSource::Portal],
])->group('RF-PD-15', 'RS-04');

it('el catalogo de codigos es cerrado y por origen', function (): void {
    // La otra mitad del control: la validacion del `FormRequest` y la del latido
    // preguntan aqui, y un codigo de quiosco desde el portal no existe.
    expect(ClientErrorCode::isKnown(ErrorSource::Kiosk, 'kiosk.camera.unavailable'))->toBeTrue()
        ->and(ClientErrorCode::isKnown(ErrorSource::Portal, 'kiosk.camera.unavailable'))->toBeFalse()
        ->and(ClientErrorCode::isKnown(ErrorSource::Admin, 'web.vue_error'))->toBeTrue()
        ->and(ClientErrorCode::isKnown(ErrorSource::Portal, 'web.vue_error'))->toBeTrue()
        ->and(ClientErrorCode::isKnown(ErrorSource::Kiosk, 'web.vue_error'))->toBeFalse()
        // Y ningun origen de servidor acepta codigos de cliente: por ahi no
        // entra nada del exterior.
        ->and(ClientErrorCode::forSource(ErrorSource::Api))->toBe([]);
})->group('RF-PD-15');
