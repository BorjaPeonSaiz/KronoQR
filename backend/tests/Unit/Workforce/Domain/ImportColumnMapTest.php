<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\ImportColumnMap;

/*
 * El mapa de columnas del fichero de plantilla (RF-GP-05, regla dura 13).
 *
 * ES CONFIGURACION Y NO CODIGO: el fichero que un hotel saca de su sistema
 * anterior trae las columnas que trae, y si adaptarse a un cliente exigiera
 * tocar el repositorio, este importador seria una consultoria encubierta
 * (ADR-017). Aqui se prueba la mecanica; que los alias de serie sean los
 * correctos se prueba de extremo a extremo en `EmployeeImportTest`.
 */

it('reconoce la cabecera exacta', function (): void {
    $map = ImportColumnMap::of(['first_name' => ['nombre', 'first_name']]);

    expect($map->fieldFor('nombre'))->toBe('first_name')
        ->and($map->fieldFor('first_name'))->toBe('first_name');
})->group('RF-GP-05');

it('ignora mayusculas, tildes, espacios y separadores', function (): void {
    // Exigir la cabecera exacta convierte un espacio de mas en un «no encuentro
    // la columna» que nadie sabe leer, y ese espacio lo pone cualquier
    // exportacion.
    $map = ImportColumnMap::of(['hired_at' => ['fecha_alta']]);

    foreach (['Fecha Alta', 'FECHA-ALTA', '  fecha alta  ', 'Fecha_Alta'] as $header) {
        expect($map->fieldFor($header))->toBe('hired_at', $header);
    }
})->group('RF-GP-05');

it('translitera los acentos del castellano y del gallego', function (): void {
    // Con una tabla explicita y no con `iconv`, que no esta en todas las
    // compilaciones de PHP: el resultado no puede depender de como este
    // compilado el PHP del servidor del hotel.
    expect(ImportColumnMap::normalise('Sección'))->toBe('seccion')
        ->and(ImportColumnMap::normalise('Año'))->toBe('ano')
        ->and(ImportColumnMap::normalise('DIRECCIÓN'))->toBe('direccion');
})->group('RF-GP-05');

it('no reconoce una cabecera que no esta en el mapa', function (): void {
    $map = ImportColumnMap::of(['first_name' => ['nombre']]);

    expect($map->fieldFor('centro_de_coste'))->toBeNull();
})->group('RF-GP-05');

it('deja ganar al alias declarado primero cuando dos campos comparten cabecera', function (): void {
    // Los alias de serie se declaran antes que los del cliente, asi que un alias
    // propio no puede robarle una cabecera estandar a otro campo por descuido.
    $map = ImportColumnMap::of([
        'first_name' => ['nombre'],
        'last_name' => ['nombre'],
    ]);

    expect($map->fieldFor('nombre'))->toBe('first_name');
})->group('RF-GP-05', 'RF-PD-01');

it('descarta un alias vacio en lugar de casar con una cabecera sin nombre', function (): void {
    // Una cabecera vacia es habitual —la columna de sobra al final de un CSV— y
    // no puede acabar mapeada a un campo.
    $map = ImportColumnMap::of(['first_name' => ['', '   ', 'nombre']]);

    expect($map->fieldFor(''))->toBeNull()
        ->and($map->fieldFor('nombre'))->toBe('first_name');
})->group('RF-GP-05');
