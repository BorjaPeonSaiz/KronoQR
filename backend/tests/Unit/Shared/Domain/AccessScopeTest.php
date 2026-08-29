<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\AccessScope;

/*
 * El alcance de una cuenta de gestion (**RF-ID-03**).
 *
 * **La distincion que sostiene el requisito** es que «sin restriccion» y «con la
 * lista vacia» no son lo mismo. Si se representaran igual, un responsable recien
 * creado —al que todavia no se le ha asignado departamento— veria la plantilla
 * entera, que es exactamente lo que RF-ID-03 existe para impedir. Estas pruebas
 * fijan esa diferencia para que nadie la «simplifique».
 */

it('alcanza a todo el mundo cuando no hay restriccion', function (): void {
    $scope = AccessScope::unrestricted();

    expect($scope->isUnrestricted())->toBeTrue()
        ->and($scope->reachesNobody())->toBeFalse()
        ->and($scope->reaches(3))->toBeTrue()
        // Tambien a quien no tiene departamento: es RRHH quien tiene que
        // corregir esa ficha.
        ->and($scope->reaches(null))->toBeTrue()
        ->and($scope->departmentIds())->toBe([]);
})->group('RF-ID-03');

it('no alcanza a nadie cuando la lista de departamentos esta vacia', function (): void {
    $scope = AccessScope::forDepartments();

    expect($scope->isUnrestricted())->toBeFalse()
        ->and($scope->reachesNobody())->toBeTrue()
        ->and($scope->reaches(3))->toBeFalse()
        ->and($scope->reaches(null))->toBeFalse();
})->group('RF-ID-03');

it('alcanza solo a los departamentos que dirige', function (): void {
    $scope = AccessScope::forDepartments(3, 7);

    expect($scope->reaches(3))->toBeTrue()
        ->and($scope->reaches(7))->toBeTrue()
        ->and($scope->reaches(5))->toBeFalse()
        // Un empleado sin departamento no lo alcanza nadie acotado: nadie dirige
        // el departamento de quien no tiene ninguno, y atribuirselo a un
        // responsable cualquiera seria inventar una jerarquia.
        ->and($scope->reaches(null))->toBeFalse();
})->group('RF-ID-03');

it('normaliza la lista para que dos alcances iguales se lean igual', function (): void {
    // Sin esto, el mismo alcance escrito en otro orden produciria otra respuesta
    // en `GET /auth/me` y otra clausula `IN` en la consulta. No cambia lo que se
    // puede ver; cambia lo que se puede comparar y depurar.
    expect(AccessScope::forDepartments(7, 3, 7)->departmentIds())->toBe([3, 7]);
})->group('RF-ID-03');

it('rechaza un identificador de departamento imposible', function (): void {
    // Un cero o un negativo no es un departamento: es un dato corrupto, y
    // aceptarlo produciria un `IN (0)` que no alcanza nada y no lo dice.
    expect(static fn (): AccessScope => AccessScope::forDepartments(0))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-ID-03');
