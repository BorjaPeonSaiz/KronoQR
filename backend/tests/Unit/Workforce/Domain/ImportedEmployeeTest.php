<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\ImportedEmployee;

/*
 * La linea de la importacion con otro idioma (RF-GP-05, RF-KI-05; bloque 26 de
 * la 2.2.1). El plan normaliza `EN` a `en` o descarta un idioma inactivo, y lo
 * hace sin perder ningun otro campo de la linea.
 */

function lineaImportadaConIdioma(?string $locale): ImportedEmployee
{
    return new ImportedEmployee(
        firstName: 'Marta',
        lastName: 'Vidal',
        email: 'marta@hotel.example',
        nationalId: '12345678-z',
        department: 'Pisos',
        hiredAt: '2026-02-01',
        locale: $locale,
    );
}

it('cambia solo el idioma y conserva el resto de la linea', function (?string $nuevo): void {
    $original = lineaImportadaConIdioma('EN');
    $cambiada = $original->withLocale($nuevo);

    expect($cambiada->locale)->toBe($nuevo)
        ->and($cambiada)->not->toBe($original)
        ->and($original->locale)->toBe('EN')
        ->and([$cambiada->firstName, $cambiada->lastName, $cambiada->email, $cambiada->department, $cambiada->hiredAt])
        ->toBe(['Marta', 'Vidal', 'marta@hotel.example', 'Pisos', '2026-02-01'])
        // El documento ya normalizado no se vuelve a tocar ni se pierde.
        ->and($cambiada->nationalId)->toBe('12345678Z')
        ->and($cambiada->identityKey())->toBe($original->identityKey());
})->with([
    'a la forma de la lista' => ['en'],
    'sin idioma' => [null],
])->group('RF-GP-05', 'RF-KI-05');
