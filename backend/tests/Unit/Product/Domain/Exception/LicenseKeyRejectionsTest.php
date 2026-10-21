<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use Tests\Support\Shared\RejectionTranslations;

/*
 * Lo que dice el rechazo de una clave de licencia al activarla (RF-PD-04).
 *
 * Quien pega la clave en el panel o en la consola tiene que saber con que campo
 * falla para pedir otra al fabricante: «clave invalida» a secas es una llamada
 * de soporte. El campo viaja en `parameters` y en el mensaje tecnico.
 */

dataset('rechazos de una clave de licencia', [
    'falta un campo' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::missingField('max_employees'),
        'license.errors.missing_field',
        ['field' => 'max_employees'],
        'The license payload has no "max_employees" field.',
    ],
    'campo que no es texto' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::fieldNotText('customer'),
        'license.errors.field_not_text',
        ['field' => 'customer'],
        'The license field "customer" must be a non-empty string.',
    ],
    'campo que no es entero' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::fieldNotInteger('max_kiosks'),
        'license.errors.field_not_integer',
        ['field' => 'max_kiosks'],
        'The license field "max_kiosks" must be an integer.',
    ],
    'limite que no es positivo' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::limitNotPositive('max_employees', 0),
        'license.errors.limit_not_positive',
        ['field' => 'max_employees', 'value' => 0],
        'The license field "max_employees" must be greater than zero, got 0.',
    ],
    'campo que no es una fecha' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::fieldNotADate('valid_until'),
        'license.errors.field_not_a_date',
        ['field' => 'valid_until'],
        'The license field "valid_until" must be an ISO-8601 instant in UTC.',
    ],
    'vigencia invertida' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::validityInverted('2027-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
        'license.errors.validity_inverted',
        ['from' => '2027-01-01T00:00:00Z', 'until' => '2026-01-01T00:00:00Z'],
        'The license validity ends (2026-01-01T00:00:00Z) before it starts (2027-01-01T00:00:00Z).',
    ],
    'funcionalidades que no son una lista' => [
        fn (): InvalidLicenseKey => InvalidLicenseKey::featuresNotAList(),
        'license.errors.features_not_a_list',
        [],
        'The license field "features" must be a list of strings.',
    ],
]);

it('dice que campo de la clave falla y por que', function (
    InvalidLicenseKey $rechazo,
    string $clave,
    array $parametros,
    string $mensaje,
): void {
    expect($rechazo->translationKey)->toBe($clave)
        ->and($rechazo->parameters)->toBe($parametros)
        ->and($rechazo->getMessage())->toBe($mensaje);
})->with('rechazos de una clave de licencia')->group('RF-PD-04');

it('tiene texto en español y en ingles con todos sus marcadores sustituidos', function (InvalidLicenseKey $rechazo): void {
    expect(RejectionTranslations::problemsWith($rechazo->translationKey, $rechazo->parameters))->toBe([]);
})->with('rechazos de una clave de licencia')->group('RF-PD-04', 'RF-KI-05');
