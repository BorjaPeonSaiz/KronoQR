<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\PhaseOrder;
use App\Console\Commands\Quality\Support\RequirementCatalog;

/*
 * docs/requisitos.yaml es la unica entrada de `--check`, asi que lo que se cuela
 * en el sin ruido deja de bloquear despues sin ruido.
 *
 * Estas pruebas comprueban que el catalogo se valida ENTERO al cargarlo y que
 * cada forma de colarse falla con su motivo, en vez de degradar a «ese requisito
 * no existe» y seguir en verde.
 */

/** Escribe un catalogo temporal y devuelve su ruta. */
function catalogFile(string $yaml): string
{
    $file = tempnam(sys_get_temp_dir(), 'kronoqr-catalog-').'.yaml';
    file_put_contents($file, $yaml);

    return $file;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-catalog-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('lee un catalogo bien formado', function (): void {
    $catalog = RequirementCatalog::fromFile(catalogFile(
        "- { id: RF-AT-01, fase: 1, titulo: Registrar entrada }\n".
        "- { id: RN-05, fase: 1, titulo: El turno no se parte a medianoche }\n"
    ));

    expect($catalog->phases())->toBe(['RF-AT-01' => 1, 'RN-05' => 1]);
})->group('RQ-13');

it('rechaza un rango sin expandir', function (): void {
    // `RF-ID-04..09` no es un identificador: seria un requisito con nombre
    // imposible que ninguna prueba puede etiquetar, y seis requisitos reales
    // sin vigilancia. Es exactamente el fallo RF-ID-09.
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile(catalogFile(
        "- { id: RF-ID-04..09, fase: 2, titulo: Credenciales }\n"
    )))->toThrow(RuntimeException::class, 'no tiene un identificador expandido');
})->group('RQ-13');

it('rechaza un requisito repetido', function (): void {
    // Dos entradas del mismo identificador con fases distintas harian que el
    // alcance del bloqueo dependiera del orden del fichero.
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile(catalogFile(
        "- { id: RN-05, fase: 1, titulo: Primera }\n".
        "- { id: RN-05, fase: 3, titulo: Segunda }\n"
    )))->toThrow(RuntimeException::class, 'repite el requisito RN-05');
})->group('RQ-13');

it('rechaza una fase que no es un entero', function (): void {
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile(catalogFile(
        "- { id: RN-05, fase: primera, titulo: El turno no se parte }\n"
    )))->toThrow(RuntimeException::class, 'no declara su fase');
})->group('RQ-13');

it('rechaza un requisito sin enunciado', function (): void {
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile(catalogFile(
        "- { id: RN-05, fase: 1, titulo: '' }\n"
    )))->toThrow(RuntimeException::class, 'no tiene enunciado');
})->group('RQ-13');

it('rechaza un tipo de verificacion inventado', function (): void {
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile(catalogFile(
        "- { id: RN-05, fase: 1, titulo: Turno, verificacion: manual }\n"
    )))->toThrow(RuntimeException::class, 'Solo se admite');
})->group('RQ-13');

it('no deja que «verificacion: revision» sea la via de escape para no probar', function (): void {
    // El campo solo vale para los requisitos de la lista cerrada del codigo. Si
    // cualquiera pudiera declararse verificado a mano, la puerta dejaria de ser
    // una puerta el dia que a alguien le corriera prisa.
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile(catalogFile(
        "- { id: RL-04, fase: 2, titulo: Conservacion, verificacion: revision }\n"
    )))->toThrow(RuntimeException::class, 'REVIEWED_BY_HAND');
})->group('RQ-13');

it('acepta «revision» solo para los requisitos enumerados en el codigo', function (): void {
    $catalog = RequirementCatalog::fromFile(catalogFile(
        "- { id: RQ-12, fase: 0, titulo: Definicion de Terminado, verificacion: revision }\n"
    ));

    expect($catalog->requirements[0]->requiresTest())->toBeFalse();
})->group('RQ-13');

it('limita el alcance del bloqueo a las fases ya ejecutadas', function (): void {
    $catalog = RequirementCatalog::fromFile(catalogFile(
        "- { id: RF-AT-01, fase: 1, titulo: Entrada }\n".
        "- { id: RF-GP-01, fase: 5, titulo: Licencia }\n".
        "- { id: RF-RE-01, fase: 3, titulo: Informe }\n"
    ));

    $inScope = $catalog->inScope(new PhaseOrder([0, 1, 2, 5, 3, 4]), 5);
    $ids = array_map(static fn ($requirement): string => $requirement->id, $inScope);

    // Cerrada la Fase 5, la 3 todavia no se ha ejecutado aunque su numero sea
    // menor. Es el orden real, no el numerico.
    expect($ids)->toBe(['RF-AT-01', 'RF-GP-01']);
})->group('RQ-13');

it('falla si el catalogo no existe, en vez de dar cero requisitos', function (): void {
    expect(fn (): RequirementCatalog => RequirementCatalog::fromFile('/no/existe/requisitos.yaml'))
        ->toThrow(RuntimeException::class, 'No se encuentra el catalogo');
})->group('RQ-13');
