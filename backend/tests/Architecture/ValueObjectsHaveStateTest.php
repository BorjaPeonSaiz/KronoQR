<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * En `Domain/ValueObject/` solo hay objetos de valor (doc 02 §3.5, H10).
 *
 * Una clase sin estado y con metodos estaticos —un formateador, un catalogo, un
 * saneador— no es un valor: no se construye, no se compara y no protege ningun
 * invariante. Mezclarlas con los objetos de valor hace que la carpeta deje de
 * decir que contiene, y que la revision de «objetos de valor `final readonly`
 * sin estados imposibles» tenga que ir apartandolas a mano. Viven en
 * `Domain/Support/`, en la misma capa: Deptrac no cambia.
 */

it('no guarda utilidades estaticas entre los objetos de valor', function (): void {
    $offenders = [];

    foreach (ModuleTree::filesInLayer(ModuleTree::MODULES, 'Domain') as $file) {
        $relative = ModuleTree::relative($file);

        if (! str_contains($relative, '/Domain/ValueObject/')) {
            continue;
        }

        $class = 'App\\Modules\\'.str_replace('/', '\\', substr($relative, 0, -4));

        if (! class_exists($class)) {
            continue; // enums e interfaces
        }

        $reflection = new ReflectionClass($class);
        $instanceState = array_filter($reflection->getProperties(), static fn (ReflectionProperty $property): bool => ! $property->isStatic());
        $statics = array_filter($reflection->getMethods(ReflectionMethod::IS_STATIC), static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class);

        if ($instanceState === [] && $statics !== []) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');
