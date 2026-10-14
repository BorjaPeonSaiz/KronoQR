<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Tests\Support\Security\HashCheckSpy;

/*
 * El espia del hasher de las pruebas de tiempo constante del PIN (RS-03). Va en
 * Feature y no en Unit porque espia la fachada `Hash`, que necesita el
 * contenedor de la aplicacion; no toca la base de datos.
 */

it('anota contra que hash se compara cada vez, en orden, y devuelve el resultado real', function (): void {
    $uno = password_hash('1111', PASSWORD_BCRYPT, ['cost' => 4]);
    $dos = password_hash('2222', PASSWORD_BCRYPT, ['cost' => 4]);
    $resultados = [];

    $comparados = HashCheckSpy::during(function () use ($uno, $dos, &$resultados): void {
        $resultados[] = Hash::check('1111', $uno);
        $resultados[] = Hash::check('1111', $dos);
    });

    expect($comparados)->toBe([$uno, $dos])
        ->and($resultados)->toBe([true, false]);
})->group('RS-03');

it('no anota nada si no se compara ningun hash', function (): void {
    expect(HashCheckSpy::during(fn (): int => 1))->toBe([]);
})->group('RS-03');

it('deja puesto el hasher real al terminar, tambien si la llamada lanza', function (): void {
    $real = app('hash');

    expect(fn () => HashCheckSpy::during(function (): void {
        throw new RuntimeException('falla a mitad');
    }))->toThrow(RuntimeException::class, 'falla a mitad')
        ->and(Hash::getFacadeRoot())->toBe($real)
        ->and(app('hash'))->toBe($real);
})->group('RS-03');
