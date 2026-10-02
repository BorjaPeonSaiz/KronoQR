<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use Tests\Contract\Support\Contract;

/*
 * **«YA ESTA DE BAJA» TIENE SU PROPIO `type`** (RN-14, ADR-046; revision del
 * bloque 17 de la 2.2.0).
 *
 * La modificacion de la ficha devuelve dos `409`: el del correo que ya es de
 * otra persona y el de la persona ya dada de baja. Con el mismo `type`, el panel
 * tomaba el del correo duplicado por una baja, cerraba la edicion y perdia lo
 * escrito. Cada endpoint que puede responder «ya esta de baja» lo declara con
 * `urn:kronoqr:problem:employee-terminated`, y el resto de sus `409` conservan
 * el generico.
 */

/**
 * Los `type` de los ejemplos de una respuesta, ordenados.
 *
 * @param  array<mixed>  $ejemplos
 * @return list<string>
 */
function tiposDeLosEjemplosDeBaja(array $ejemplos): array
{
    $tipos = [];

    foreach ($ejemplos as $ejemplo) {
        \assert(\is_array($ejemplo) && \is_array($ejemplo['value'] ?? null));
        $tipo = $ejemplo['value']['type'] ?? null;
        \assert(\is_string($tipo));
        $tipos[] = $tipo;
    }

    sort($tipos);

    return $tipos;
}

it('declara el type de la baja junto al conflicto generico en la respuesta comun de la ficha', function (): void {
    $ejemplos = Contract::map('components', 'responses', 'EmployeeConflict', 'content', 'application/problem+json', 'examples');

    expect(tiposDeLosEjemplosDeBaja($ejemplos))->toBe([ProblemDetails::TYPE_CONFLICT, ProblemDetails::TYPE_EMPLOYEE_TERMINATED])
        ->and(ProblemDetails::TYPE_EMPLOYEE_TERMINATED)->toBe('urn:kronoqr:problem:employee-terminated');
})->group('RN-14', 'RF-GP-01', 'RF-GP-03');

it('la usan los endpoints que pueden responder que la persona ya esta de baja', function (string $path, string $method): void {
    expect(Contract::value('paths', $path, $method, 'responses', '409', '$ref'))
        ->toBe('#/components/responses/EmployeeConflict');
})->with([
    'modificacion' => ['/api/v1/employees/{uuid}', 'patch'],
    'baja' => ['/api/v1/employees/{uuid}/offboard', 'post'],
    'restablecimiento del PIN' => ['/api/v1/employees/{uuid}/pin/reset', 'post'],
    'entrega del PIN' => ['/api/v1/employees/{uuid}/pin/deliver', 'post'],
])->group('RN-14', 'RF-GP-01', 'RF-GP-03', 'RF-ID-09');

it('la importacion declara los dos type de su 409', function (): void {
    $ejemplos = Contract::map(
        'paths', '/api/v1/employees/import', 'post', 'responses', '409',
        'content', 'application/problem+json', 'examples',
    );

    expect(tiposDeLosEjemplosDeBaja($ejemplos))->toBe([ProblemDetails::TYPE_CONFLICT, ProblemDetails::TYPE_EMPLOYEE_TERMINATED]);
})->group('RN-14', 'RF-GP-05');
