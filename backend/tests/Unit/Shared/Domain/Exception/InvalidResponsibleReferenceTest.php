<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exception\InvalidResponsibleReference;
use Tests\Support\Shared\RejectionTranslations;

/*
 * Lo que dice el rechazo de la cuenta responsable que se nombra en consola
 * (RL-04, doc 01 §7.2).
 *
 * Quien firma un asiento por consola tiene que saber por que no le vale lo que
 * ha escrito: no es lo mismo no haber puesto nada que haber puesto el id
 * interno, que hasta la 2.3.0 se aceptaba.
 */

dataset('rechazos de la cuenta responsable', [
    'vacia' => [
        fn (): InvalidResponsibleReference => InvalidResponsibleReference::empty(),
        'responsible.missing',
        'The responsible account reference is empty.',
    ],
    'id interno' => [
        fn (): InvalidResponsibleReference => InvalidResponsibleReference::numericId(),
        'responsible.numeric_id',
        'The responsible account must be given by its uuid or e-mail since 2.3.0, not by its internal id.',
    ],
    'ni uuid ni correo' => [
        fn (): InvalidResponsibleReference => InvalidResponsibleReference::malformed(),
        'responsible.malformed',
        'The responsible account reference is not a valid uuid or e-mail address.',
    ],
]);

it('dice por que no vale la referencia', function (
    InvalidResponsibleReference $rechazo,
    string $clave,
    string $mensaje,
): void {
    expect($rechazo->translationKey)->toBe($clave)
        ->and($rechazo->getMessage())->toBe($mensaje);
})->with('rechazos de la cuenta responsable')->group('RL-04');

it('tiene texto en español y en ingles con el valor que pone la consola', function (InvalidResponsibleReference $rechazo): void {
    // El rechazo no lleva lo que se escribio, que puede ser un correo: el
    // valor lo pone `ConsoleResponsible`, que es quien lo tiene delante.
    expect(RejectionTranslations::problemsWith($rechazo->translationKey, ['value' => '42']))->toBe([]);
})->with('rechazos de la cuenta responsable')->group('RL-04', 'RF-KI-05');
