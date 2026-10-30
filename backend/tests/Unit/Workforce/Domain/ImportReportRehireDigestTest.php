<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\ImportedEmployee;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;
use App\Modules\Workforce\Domain\ValueObject\ImportRow;

/*
 * La huella de las readmisiones de un informe de importacion (RN-27, RF-GP-05):
 * la validacion la devuelve y la aplicacion la exige, para que no se readmita a
 * nadie que no estuviera en el resumen revisado.
 */

function filaDeHuella(string $nombre): ImportedEmployee
{
    return new ImportedEmployee(
        firstName: $nombre,
        lastName: 'Prueba',
        email: null,
        nationalId: null,
        department: null,
        hiredAt: '2026-09-01',
        locale: null,
    );
}

it('no hay huella si nadie se readmite', function (): void {
    $informe = ImportReport::of(str_repeat('a', 64), [ImportRow::created(2, filaDeHuella('Ana'))], false);

    expect($informe->rehireDigest())->toBeNull();
})->group('RN-27', 'RF-GP-05');

it('la huella es la de los uuid readmitidos, ordenados, sin depender del orden de las filas', function (): void {
    $a = '01a00000-0000-7000-8000-000000000001';
    $b = '01a00000-0000-7000-8000-000000000002';

    $uno = ImportReport::of(str_repeat('a', 64), [
        ImportRow::rehire(2, filaDeHuella('Ana'), $b, []),
        ImportRow::created(3, filaDeHuella('Eva')),
        ImportRow::rehire(4, filaDeHuella('Luz'), $a, []),
    ], false);
    $otro = ImportReport::of(str_repeat('b', 64), [
        ImportRow::rehire(2, filaDeHuella('Luz'), $a, []),
        ImportRow::rehire(3, filaDeHuella('Ana'), $b, []),
    ], false);

    expect($uno->rehireDigest())->toBe(hash('sha256', $a."\n".$b))
        ->and($otro->rehireDigest())->toBe($uno->rehireDigest())
        ->and(ImportReport::of(str_repeat('a', 64), [ImportRow::rehire(2, filaDeHuella('Ana'), $a, [])], false)->rehireDigest())
        ->not->toBe($uno->rehireDigest());
})->group('RN-27', 'RF-GP-05');
