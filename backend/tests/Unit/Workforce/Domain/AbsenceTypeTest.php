<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/*
 * `AbsenceType` — el catalogo cerrado y sus alias de carga (**RF-GP-04**, tarea
 * 3.10).
 *
 * Lo que se defiende aqui es que **el catalogo no crece por descuido** —es lo
 * que hace comparables los informes de dos clientes— y que los alias castellanos
 * solo existen para el fichero, no para la API.
 */

it('declara exactamente las cuatro categorias del producto', function (): void {
    // Si alguien añade una quinta, esta prueba lo dice en voz alta: un catalogo
    // abierto haria que el informe de absentismo de un hotel no se pudiera
    // comparar con el suyo del año pasado.
    expect(AbsenceType::names())->toBe(['vacation', 'sick_leave', 'leave', 'other']);
})->group('RF-GP-04');

it('solo `other` exige nota', function (): void {
    expect(AbsenceType::Other->requiresNote())->toBeTrue();

    foreach ([AbsenceType::Vacation, AbsenceType::SickLeave, AbsenceType::Leave] as $type) {
        expect($type->requiresNote())->toBeFalse();
    }
})->group('RF-GP-04');

it('reconoce los nombres castellanos de la carga, sin distinguir mayusculas ni tildes', function (string $etiqueta, string $esperado): void {
    // Quien rellena un cuadrante en una hoja de calculo escribe «vacaciones»,
    // no `vacation`. La comparacion es tolerante con la misma norma que el mapa
    // de columnas, porque una exportacion trae «Vacaciones», «BAJA MÉDICA» y
    // «baja_medica» indistintamente.
    expect(AbsenceType::fromImportLabel($etiqueta)?->value)->toBe($esperado);
})->with([
    'ingles' => ['vacation', 'vacation'],
    'castellano' => ['vacaciones', 'vacation'],
    'con mayusculas' => ['VACACIONES', 'vacation'],
    // El singular, que es como lo escribe quien anota una sola jornada.
    'castellano en singular' => ['vacacion', 'vacation'],
    'baja' => ['Baja', 'sick_leave'],
    'baja medica con tilde' => ['Baja médica', 'sick_leave'],
    'incapacidad temporal' => ['IT', 'sick_leave'],
    'permiso' => ['permiso', 'leave'],
    'otro' => ['Otro', 'other'],
    // LOS TRES NOMBRES CANONICOS EN INGLES que faltaban, y no sobran: son los
    // que sale de una EXPORTACION del propio producto, que es el fichero mas
    // probable de todos —el cliente exporta, corrige en la hoja y vuelve a
    // cargar—. Sin ellos, borrar la fila `'sick_leave' => SickLeave` del mapa de
    // alias no rompia ninguna prueba: la carga de la exportacion propia dejaba
    // de reconocer la baja y nadie se enteraba hasta el fichero del cliente.
    'baja en ingles' => ['sick_leave', 'sick_leave'],
    'permiso en ingles' => ['leave', 'leave'],
    'otro en ingles' => ['other', 'other'],
    // Y el plural castellano de `other`, por lo mismo que el de `vacation`.
    'otros en plural' => ['Otros', 'other'],
])->group('RF-GP-04');

it('devuelve null para una etiqueta que no reconoce', function (): void {
    // Un tipo inventado en el fichero es un rechazo de linea con su codigo, no
    // una categoria nueva por la puerta de atras.
    expect(AbsenceType::fromImportLabel('excedencia'))->toBeNull();
    expect(AbsenceType::fromImportLabel(''))->toBeNull();
})->group('RF-GP-04');
