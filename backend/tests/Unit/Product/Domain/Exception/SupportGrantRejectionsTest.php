<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSupportGrant;
use App\Modules\Product\Domain\Exception\SupportGrantAlreadyClosed;
use Tests\Support\Shared\RejectionTranslations;

/*
 * Lo que dicen los rechazos de una concesion de acceso al fabricante
 * (RF-PD-11, ADR-020, regla dura 16).
 *
 * La concesion la da una persona del cliente desde el panel: el `422` tiene que
 * decirle cuanto puede durar y cuanto ha pedido, en su idioma.
 */

dataset('rechazos de una concesion de soporte', [
    'motivo de longitud invalida' => [
        fn (): InvalidSupportGrant => InvalidSupportGrant::reason(2),
        'support.errors.reason_length',
        ['length' => 2],
        'A support grant needs a reason of 3 to 200 characters, got 2.',
    ],
    'motivo con un dato personal' => [
        fn (): InvalidSupportGrant => InvalidSupportGrant::personalData('phone'),
        'support.errors.reason_personal_data',
        [],
        'A support grant reason must not carry personal data, and this one looks like it carries a phone.',
    ],
    'duracion fuera de rango' => [
        fn (): InvalidSupportGrant => InvalidSupportGrant::duration(73, 72),
        'support.errors.duration',
        ['hours' => 73, 'maximum' => 72],
        'A support grant lasts from 1 to 72 hours, got 73.',
    ],
]);

it('dice que limite incumple la concesion y con que valor', function (
    InvalidSupportGrant $rechazo,
    string $clave,
    array $parametros,
    string $mensaje,
): void {
    expect($rechazo->translationKey)->toBe($clave)
        ->and($rechazo->parameters)->toBe($parametros)
        ->and($rechazo->getMessage())->toBe($mensaje);
})->with('rechazos de una concesion de soporte')->group('RF-PD-11');

it('tiene texto en español y en ingles con todos sus marcadores sustituidos', function (InvalidSupportGrant $rechazo): void {
    expect(RejectionTranslations::problemsWith($rechazo->translationKey, $rechazo->parameters))->toBe([]);
})->with('rechazos de una concesion de soporte')->group('RF-PD-11', 'RF-KI-05');

it('dice que concesion estaba ya revocada', function (): void {
    $rechazo = new SupportGrantAlreadyClosed('0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60');

    expect($rechazo->uuid)->toBe('0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60')
        ->and($rechazo->getMessage())->toBe('The support grant "0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60" was already revoked.');
})->group('RF-PD-11');
