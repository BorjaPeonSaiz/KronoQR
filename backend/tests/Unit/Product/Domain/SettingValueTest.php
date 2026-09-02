<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;

/*
 * El valor de configuracion como objeto de valor (RF-PD-01).
 *
 * Lo que se comprueba aqui es que no se puede construir uno invalido y que
 * quien lo recibe no tiene que volver a comprobar nada, que es lo que evita la
 * tercera copia de la validacion en el FormRequest y en el adaptador.
 */

it('no deja construir un valor que su clave no admite', function (): void {
    expect(fn (): SettingValue => SettingValue::of(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, 99))
        ->toThrow(InvalidSettingValue::class);
})->group('RF-PD-01');

it('conserva el valor validado y de donde sale', function (): void {
    $value = SettingValue::of(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, 10);

    expect($value->asInteger())->toBe(10)
        ->and($value->value())->toBe(10)
        ->and($value->isProductDefault)->toBeFalse();
})->group('RF-PD-01');

it('rechaza leer un valor con el tipo equivocado', function (): void {
    // No es un caso del cliente sino un error de programacion, y falla en el
    // acto en vez de propagar una cadena donde se esperaba un umbral.
    $value = SettingValue::of(SettingKey::BRANDING_APP_NAME, 'Hotel de prueba');

    expect(fn (): int => $value->asInteger())->toThrow(InvalidSettingValue::class);
})->group('RF-PD-01');

it('compara por clave y valor, no por procedencia', function (): void {
    // Es lo que necesita el PATCH para no escribir fila ni asiento de auditoria
    // cuando el administrador guarda la pantalla sin haber tocado nada.
    $configured = SettingValue::of(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS, 60);
    $default = SettingValue::productDefault(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS);

    expect($configured->equals($default))->toBeTrue()
        ->and($configured->isProductDefault)->toBeFalse()
        ->and($default->isProductDefault)->toBeTrue();
})->group('RF-PD-01');

it('sabe si el cambio de esta clave puede mover los minutos del registro', function (): void {
    // Lo escribe el asiento de auditoria del PATCH (paso 8 de la tarea 5.1).
    $debounce = SettingValue::productDefault(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS);
    $accent = SettingValue::productDefault(SettingKey::BRANDING_ACCENT_COLOR);

    expect($debounce->affectsWorkedHours())->toBeTrue()
        ->and($accent->affectsWorkedHours())->toBeFalse();
})->group('RF-PD-01');

it('admite la ruta de logotipo vacia, que significa el logotipo del producto', function (): void {
    $value = SettingValue::of(SettingKey::BRANDING_LOGO_PATH, '');

    expect($value->asText())->toBe('');
})->group('RF-PD-01');
