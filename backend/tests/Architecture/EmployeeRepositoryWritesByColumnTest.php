<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Port\EmployeeRepository;

/*
 * **LA FICHA DEL EMPLEADO NO SE REESCRIBE ENTERA** (ADR-046 §2 y §3; hallazgos
 * R7-RV-01 y R4-BE-01).
 *
 * Con un `save()` que escribia la fila completa, una modificacion de nombre que
 * habia leido la ficha antes de una baja reescribia `status` y `terminated_at`
 * y deshacia la baja. El puerto ya no ofrece esa escritura: quien escribe la
 * ficha lee con `findForUpdate()` y elige sus columnas con `saveProfile()` o
 * `saveTermination()`. Si alguien vuelve a declarar `save()`, esta prueba lo
 * dice antes de que un caso de uso lo use.
 */

it('el puerto de la plantilla no declara save() y si las escrituras por columnas', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        new ReflectionClass(EmployeeRepository::class)->getMethods(),
    );

    expect($methods)->not->toContain('save')
        ->and($methods)->toContain('findForUpdate', 'saveProfile', 'saveTermination');
})->group('RF-GP-01', 'RF-GP-03', 'RN-14');
