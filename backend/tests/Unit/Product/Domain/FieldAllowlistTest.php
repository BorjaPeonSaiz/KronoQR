<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\FieldAllowlist;

/*
 * El anonimizador del paquete de diagnostico (RF-PD-09, RL-19, ADR-020, regla
 * dura 21).
 *
 * La ficha 5.9 lo pide con estas palabras: «dada una estructura con nombres,
 * correos, DNI y horas de fichaje, la salida **no contiene ninguno**; los
 * identificadores son UUID».
 *
 * Y lo importante no es que la salida no los lleve HOY: es que **no los pueda
 * llevar mañana**. Por eso la prueba central es la del campo nuevo — el dia que
 * alguien añada `employees.nickname`, esta suite tiene que seguir en verde y el
 * apodo tiene que quedarse fuera. Con una lista de exclusiones, esa prueba
 * pasaria y el apodo viajaria al fabricante.
 */

/**
 * Una fila de empleado tal como sale de la base de datos, con todo dentro.
 *
 * @return array<string, mixed>
 */
function filaDeEmpleado(): array
{
    return [
        'id' => 42,
        'uuid' => '01a04d03-3ff1-7056-a5c0-f0cdac41b9a1',
        'employee_code' => 'E0001',
        'first_name' => 'Marta',
        'last_name' => 'Lopez Garcia',
        'email' => 'marta.lopez@hotel.example',
        'national_id_hash' => '12345678Z-hash',
        'pin_hash' => '$2y$12$abcdefghijklmnopqrstuv',
        'photo_path' => '/var/kronoqr/fotos/marta.jpg',
        'status' => 'active',
        'department_id' => 3,
        'client_meta' => ['user_agent' => 'Tablet de Marta', 'ip' => '10.0.20.14'],
    ];
}

it('deja fuera nombres, correos, DNI y todo lo que no este en la lista', function (): void {
    $allowlist = new FieldAllowlist('uuid', 'employee_code', 'status', 'department_id');

    $anonymized = $allowlist->apply(filaDeEmpleado());

    expect(array_keys($anonymized))->toBe(['uuid', 'employee_code', 'status', 'department_id']);

    $serialized = json_encode($anonymized, JSON_THROW_ON_ERROR);

    foreach ([
        'Marta', 'Lopez', 'marta.lopez@hotel.example', '12345678Z',
        '$2y$12$', '/var/kronoqr/fotos', 'Tablet de Marta', '10.0.20.14',
    ] as $forbidden) {
        expect($serialized)->not->toContain($forbidden);
    }

    // Lo que si sale identifica a la persona **solo dentro de esta instalacion**
    // (ADR-020): el UUID y su codigo de empleado, que es lo que permite a
    // soporte hablar de «esa persona» sin saber quien es.
    expect($anonymized['uuid'])->toBe('01a04d03-3ff1-7056-a5c0-f0cdac41b9a1');
})->group('RF-PD-09', 'RL-19');

it('no deja pasar un campo nuevo que nadie ha revisado', function (): void {
    // LA PRUEBA QUE JUSTIFICA LA LISTA DE PERMITIDOS. Con una de exclusiones,
    // este caso pasaria y el campo nuevo viajaria al fabricante en silencio.
    $allowlist = new FieldAllowlist('uuid', 'status');

    $conCampoNuevo = [...filaDeEmpleado(), 'nickname' => 'Martita', 'private_note' => 'baja por maternidad'];

    $anonymized = $allowlist->apply($conCampoNuevo);

    $serialized = json_encode($anonymized, JSON_THROW_ON_ERROR);

    expect(array_keys($anonymized))->toBe(['uuid', 'status'])
        ->and($serialized)->not->toContain('Martita')
        ->and($serialized)->not->toContain('maternidad');
})->group('RF-PD-09', 'RL-19');

it('corta un mapa anidado aunque su clave este permitida', function (): void {
    // Permitir `client_meta` colaria el objeto entero que lleve dentro, cuyas
    // claves la lista no puede describir. Es la via por la que se filtra lo que
    // la tablet quiso mandar y nadie reviso.
    $allowlist = new FieldAllowlist('uuid', 'client_meta');

    $anonymized = $allowlist->apply(filaDeEmpleado());

    expect($anonymized['client_meta'])->toBeNull()
        ->and(json_encode($anonymized, JSON_THROW_ON_ERROR))->not->toContain('Tablet de Marta');
})->group('RF-PD-09', 'RL-19');

it('conserva una lista de escalares, que no puede ocultar claves', function (): void {
    // `features` de la licencia es una lista de cadenas: no tiene claves detras
    // de las que esconder nada, y recortarla dejaria el paquete sin informacion
    // util sin ganar nada.
    $allowlist = new FieldAllowlist('features');

    expect($allowlist->apply(['features' => ['advanced_reports', 'white_label']]))
        ->toBe(['features' => ['advanced_reports', 'white_label']]);
})->group('RF-PD-09');

it('rellena con nulo lo permitido que falta, para distinguirlo de lo que no viaja', function (): void {
    // Sin esto, soporte no puede diferenciar «esa columna estaba vacia» de «esa
    // columna no sale del paquete», y esas dos cosas llevan a diagnosticos
    // distintos.
    $allowlist = new FieldAllowlist('uuid', 'app_version');

    expect($allowlist->apply(['uuid' => 'abc']))->toBe(['uuid' => 'abc', 'app_version' => null]);
})->group('RF-PD-09');

it('impone el orden declarado, para que dos paquetes se puedan comparar', function (): void {
    $allowlist = new FieldAllowlist('uuid', 'status', 'employee_code');

    expect(array_keys($allowlist->apply(['employee_code' => 'E1', 'status' => 'active', 'uuid' => 'u'])))
        ->toBe(['uuid', 'status', 'employee_code']);
})->group('RF-PD-09');

it('aplica la misma lista a una coleccion entera', function (): void {
    $allowlist = new FieldAllowlist('uuid');

    $rows = [filaDeEmpleado(), [...filaDeEmpleado(), 'uuid' => 'otro', 'first_name' => 'Juan']];

    expect($allowlist->applyAll($rows))->toBe([['uuid' => '01a04d03-3ff1-7056-a5c0-f0cdac41b9a1'], ['uuid' => 'otro']])
        ->and(json_encode($allowlist->applyAll($rows), JSON_THROW_ON_ERROR))->not->toContain('Juan');
})->group('RF-PD-09', 'RL-19');
